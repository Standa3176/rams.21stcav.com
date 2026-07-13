@extends('layouts.app')

@section('title', 'Schematic Editor Spike')

@section('content')
    {{-- Task 1 stub — Task 4 replaces this with an inline <details> walkthrough
         and the React root fills the canvas area. --}}
    <div id="schematic-spike-root" style="height: calc(100vh - 60px); width: 100%;"></div>
    @vite(['resources/js/spike/main.jsx'])
@endsection
