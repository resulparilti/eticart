@props(['content' => ''])
@php
    $html = (string) $content;
    $isHtml = $html !== '' && $html !== strip_tags($html);
@endphp
@if ($isHtml)
    {!! $html !!}
@else
    {!! nl2br(e($html)) !!}
@endif
