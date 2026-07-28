@extends('errors.layout')

@section('title', 'No access')

@section('message')
    {{ $exception->getMessage() ?: 'You do not have access to this page.' }}
@endsection
