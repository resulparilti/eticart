@extends('email.layout')

@section('heading', $title ?? 'Bildirim')

@section('content')
    <x-template-html :content="$body" />
@endsection
