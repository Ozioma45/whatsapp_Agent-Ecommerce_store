@extends('layouts.app')

@section('title', config('app.name'))

@section('content')
    <div class="flex flex-col items-center justify-center gap-4 py-20 text-center">
        <h1 class="text-3xl font-semibold sm:text-4xl">{{ config('app.name') }}</h1>
        <p class="max-w-xl text-gray-600">
            The project foundation is up and running. Businesses will soon be able to create a store here
            and take orders through WhatsApp.
        </p>
    </div>
@endsection
