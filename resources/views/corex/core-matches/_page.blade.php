{{-- Core Matches board — one batch of contacts, fetched by the board when the
     contact list is scrolled to its end (ContactMatchController::index, AJAX).
     Two templates: list rows and searches panes; the board appends each to its
     own column and reads the next batch URL from data-cm-next. --}}
@php $showContactAgent = $scope !== 'own'; @endphp
<template data-cm-list>@include('corex.core-matches._list', ['withTour' => false])</template>
<template data-cm-panes>@include('corex.core-matches._panes', ['withTour' => false])</template>
<div data-cm-next="{{ $nextPageUrl ?? '' }}"></div>
