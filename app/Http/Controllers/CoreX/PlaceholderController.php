<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PlaceholderController extends Controller
{
    private array $sections = [
        'documents' => [
            'title' => 'Documents',
            'description' => 'Document management, templates, and digital signing coming soon.',
            'icon' => 'document',
        ],
        'compliance' => [
            'title' => 'Compliance',
            'description' => 'FICA, regulatory compliance tracking, and audit trails coming soon.',
            'icon' => 'shield',
        ],
        'supervision' => [
            'title' => 'Supervision',
            'description' => 'Agent supervision, mentorship tracking, and oversight tools coming soon.',
            'icon' => 'eye',
        ],
        'training' => [
            'title' => 'Training (LMS)',
            'description' => 'Learning management, CPD tracking, and course materials coming soon.',
            'icon' => 'academic-cap',
        ],
        'communication' => [
            'title' => 'Communication',
            'description' => 'Team messaging, announcements, and notification center coming soon.',
            'icon' => 'chat',
        ],
        'client-portal' => [
            'title' => 'Client Portal',
            'description' => 'Client-facing portal for property updates and document exchange coming soon.',
            'icon' => 'users',
        ],
        'franchise-admin' => [
            'title' => 'Franchise Admin',
            'description' => 'Multi-branch franchise management and reporting coming soon.',
            'icon' => 'building',
        ],
    ];

    public function show(Request $request, string $section = 'documents')
    {
        $info = $this->sections[$section] ?? $this->sections['documents'];

        return view('corex.placeholder', [
            'section' => $section,
            'title' => $info['title'],
            'description' => $info['description'],
            'icon' => $info['icon'],
        ]);
    }
}
