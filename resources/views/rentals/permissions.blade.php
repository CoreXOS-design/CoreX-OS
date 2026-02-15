@extends('layouts.app')

@section('content')

<div class="max-w-4xl mx-auto px-4 py-6">

    <h1 class="text-2xl font-bold mb-6">
        Rental Permissions
    </h1>

    <form method="POST" action="{{ route('rentals.permissions.update') }}">

        @csrf

        <div class="bg-white shadow rounded p-6">

            <table class="min-w-full">

                <thead>
                    <tr>
                        <th class="text-left px-3 py-2">User</th>
                        <th class="text-center px-3 py-2">Can Capture Rentals</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($users as $user)

                        <tr class="border-t">

                            <td class="px-3 py-2">
                                {{ $user->name }}
                            </td>

                            <td class="px-3 py-2 text-center">

                                <input type="checkbox"
                                       name="can_capture_rentals[]"
                                       value="{{ $user->id }}"
                                       {{ $user->can_capture_rentals ? 'checked' : '' }}>

                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

            <div class="mt-4">

                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">

                    Save Permissions

                </button>

            </div>

        </div>

    </form>

</div>

@endsection
