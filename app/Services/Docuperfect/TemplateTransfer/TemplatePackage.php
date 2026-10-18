<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use ZipArchive;

/**
 * The on-disk shape of an e-sign template package (.cxpkg) and of a multi-template
 * bundle (.zip of packages): building, reading and validating the container. Knows
 * nothing about the database. Spec: .ai/specs/esign-template-transfer.md §3.
 */
final class TemplatePackage
{
    public const FORMAT = 'corex-template-package';
    public const BUNDLE_FORMAT = 'corex-template-bundle';
    public const VERSION = 1;

    private const MAX_ENTRIES = 600;
    private const MAX_JSON_BYTES = 16 * 1024 * 1024;
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;
    /** Uncompressed total allowed = package cap x this (zip-bomb guard). */
    private const EXPANSION_FACTOR = 5;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string,mixed> $templateJson   contents of template.json
     * @param array<string,string> $files          'files/page-0.png' => bytes
     * @return array{bytes:string, checksum:string, manifest:array}
     */
    public static function build(array $templateJson, array $files, string $templateName, string $sourceLabel): array
    {
        $entries = ['template.json' => json_encode($templateJson, self::JSON_FLAGS | JSON_THROW_ON_ERROR)] + $files;
        $hashes = [];
        foreach ($entries as $name => $bytes) {
            $hashes[$name] = hash('sha256', $bytes);
        }
        ksort($hashes);
        $checksum = self::checksumOf($hashes);

        $manifest = [
            'format'           => self::FORMAT,
            'format_version'   => self::VERSION,
            'exported_at'      => now()->toIso8601String(),
            'source_label'     => $sourceLabel,
            'template_name'    => $templateName,
            'files'            => $hashes,
            'package_checksum' => $checksum,
        ];
        $entries = ['manifest.json' => json_encode($manifest, self::JSON_FLAGS | JSON_THROW_ON_ERROR)] + $entries;

        return ['bytes' => self::zip($entries), 'checksum' => $checksum, 'manifest' => $manifest];
    }

    /**
     * Open + fully validate a package container (format, version, entry names, sizes,
     * checksums). Returns the decoded parts; never writes anything.
     *
     * @return array{manifest:array, data:array, files:array<string,string>, checksum:string}
     * @throws TemplateTransferException
     */
    public static function read(string $path): array
    {
        $entries = self::openEntries($path, 'package');

        foreach (array_keys($entries) as $name) {
            if (! preg_match('#^(manifest\.json|template\.json|files/page-\d{1,4}\.png)$#', $name)) {
                throw new TemplateTransferException('This file is not a CoreX template package (it contains parts that do not belong in one).');
            }
        }
        if (! isset($entries['manifest.json']) || ! isset($entries['template.json'])) {
            throw new TemplateTransferException('This package is incomplete — a required part is missing, so nothing can be imported from it.');
        }

        $manifest = json_decode($entries['manifest.json'], true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new TemplateTransferException('This file is not a CoreX template package.');
        }
        $version = $manifest['format_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new TemplateTransferException('This package has no valid format version.');
        }
        if ($version > self::VERSION) {
            throw new TemplateTransferException("This package was made by a newer version of CoreX (package format {$version}; this system reads format " . self::VERSION . '). Update this system first, or export the template again from a matching version.');
        }
        if ($version < self::VERSION) {
            throw new TemplateTransferException("This package is in an old format ({$version}) that this system can no longer read. Export the template again.");
        }

        $declared = $manifest['files'] ?? null;
        if (! is_array($declared)) {
            throw new TemplateTransferException('This package has no file list, so it cannot be checked.');
        }
        $actual = [];
        foreach ($entries as $name => $bytes) {
            if ($name !== 'manifest.json') {
                $actual[$name] = hash('sha256', $bytes);
            }
        }
        ksort($actual);
        ksort($declared);
        if ($declared !== $actual) {
            throw new TemplateTransferException('This package failed its integrity check — it was changed or damaged after it was exported. Nothing was imported. Export the template again.');
        }
        $checksum = self::checksumOf($actual);
        if (! is_string($manifest['package_checksum'] ?? null) || ! hash_equals($checksum, $manifest['package_checksum'])) {
            throw new TemplateTransferException('This package failed its integrity check — it was changed or damaged after it was exported. Nothing was imported. Export the template again.');
        }

        $data = json_decode($entries['template.json'], true);
        if (! is_array($data) || ! is_array($data['template'] ?? null)) {
            throw new TemplateTransferException('This package is damaged: its template description cannot be read.');
        }

        $files = [];
        foreach ($entries as $name => $bytes) {
            if (str_starts_with($name, 'files/')) {
                if (! str_starts_with($bytes, "\x89PNG")) {
                    throw new TemplateTransferException('This package is damaged: a page image is not a valid image.');
                }
                $files[$name] = $bytes;
            }
        }

        return ['manifest' => $manifest, 'data' => $data, 'files' => $files, 'checksum' => $checksum];
    }

    /** True when the file is a multi-template bundle rather than a single package. */
    public static function isBundle(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $isBundle = $zip->locateName('bundle.json') !== false;
        $zip->close();

        return $isBundle;
    }

    /**
     * @param array<string,string> $packages  filename => package bytes
     */
    public static function buildBundle(array $packages): string
    {
        $index = [];
        foreach ($packages as $name => $bytes) {
            $index[] = ['file' => $name, 'sha256' => hash('sha256', $bytes)];
        }
        $entries = ['bundle.json' => json_encode([
            'format'         => self::BUNDLE_FORMAT,
            'format_version' => self::VERSION,
            'exported_at'    => now()->toIso8601String(),
            'packages'       => $index,
        ], self::JSON_FLAGS | JSON_THROW_ON_ERROR)] + $packages;

        return self::zip($entries);
    }

    /**
     * Unpack a bundle into [filename => package bytes], checking each package's hash.
     *
     * @return array<string,string>
     * @throws TemplateTransferException
     */
    public static function readBundle(string $path): array
    {
        $entries = self::openEntries($path, 'bundle');
        $index = json_decode($entries['bundle.json'] ?? '', true);
        if (! is_array($index) || ($index['format'] ?? null) !== self::BUNDLE_FORMAT || ! is_array($index['packages'] ?? null)) {
            throw new TemplateTransferException('This file is not a CoreX template bundle.');
        }
        if (($index['format_version'] ?? null) !== self::VERSION) {
            throw new TemplateTransferException('This bundle was made in a package format this system cannot read.');
        }
        $max = TemplateTransferSettings::int('max_bundle_templates');
        if (count($index['packages']) < 1 || count($index['packages']) > $max) {
            throw new TemplateTransferException("A bundle can hold 1 to {$max} templates.");
        }

        $out = [];
        foreach ($index['packages'] as $p) {
            $file = is_array($p) ? ($p['file'] ?? null) : null;
            if (! is_string($file) || ! preg_match('#^[A-Za-z0-9._\- ]{1,120}\.cxpkg$#', $file) || ! isset($entries[$file])) {
                throw new TemplateTransferException('This bundle is incomplete — a package listed in it is missing.');
            }
            if (! hash_equals((string) ($p['sha256'] ?? ''), hash('sha256', $entries[$file]))) {
                throw new TemplateTransferException('This bundle failed its integrity check — it was changed or damaged after it was exported. Nothing was imported.');
            }
            $out[$file] = $entries[$file];
        }

        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @return array<string,string> entry name => bytes
     * @throws TemplateTransferException
     */
    private static function openEntries(string $path, string $what): array
    {
        $cap = TemplateTransferSettings::maxPackageBytes();
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new TemplateTransferException('The file is empty.');
        }
        if ($size > $cap) {
            throw new TemplateTransferException('The file is larger than the allowed ' . TemplateTransferSettings::int('max_package_mb') . ' MB.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new TemplateTransferException("This is not a CoreX template {$what} (it cannot be opened).");
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new TemplateTransferException("This is not a valid CoreX template {$what}.");
            }

            $total = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $isJson = str_ends_with($name, '.json');
                $limit = $isJson ? self::MAX_JSON_BYTES : ($what === 'bundle' ? $cap : self::MAX_IMAGE_BYTES);
                if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new TemplateTransferException("This {$what} contains parts that do not belong in it.");
                }
                $total += (int) $stat['size'];
                if ((int) $stat['size'] > $limit || $total > $cap * self::EXPANSION_FACTOR) {
                    throw new TemplateTransferException("This {$what} expands to more than is allowed and was refused.");
                }
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    throw new TemplateTransferException("This {$what} is damaged and cannot be read.");
                }
                $entries[$name] = $bytes;
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string,string> $hashes name => sha256 (sorted) */
    private static function checksumOf(array $hashes): string
    {
        ksort($hashes);
        $lines = '';
        foreach ($hashes as $name => $hash) {
            $lines .= $name . ':' . $hash . "\n";
        }

        return hash('sha256', $lines);
    }

    /** @param array<string,string> $entries */
    private static function zip(array $entries): string
    {
        $dir = storage_path('app/template-transfer/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = tempnam($dir, 'pkg');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($tmp);
            throw new TemplateTransferException('The package file could not be created.');
        }
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
