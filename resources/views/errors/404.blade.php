@extends('errors.layout')

@section('title', 'Not found')

@section('message')
    {{ $exception->getMessage() ?: 'We could not find that page.' }}
@endsection
