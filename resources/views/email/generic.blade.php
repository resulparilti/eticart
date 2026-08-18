@extends('email.layout')

@section('content')
    <h1 style="font-size:20px;margin:0 0 16px;">{{ $title }}</h1>
    <x-template-html :content="$body" />
@endsection
