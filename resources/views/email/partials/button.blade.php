@props([
    'url' => '',
    'label' => 'Devam et',
    'background' => '#000000',
    'color' => '#ffffff',
    'width' => 240,
])
@php
    $url = trim((string) $url);
    $label = trim((string) $label);
    $background = preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $background) === 1
        ? $background
        : '#000000';
    $color = preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $color) === 1
        ? $color
        : '#ffffff';
    $width = max(160, min(320, (int) $width));
@endphp
@if ($url !== '')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="left" style="margin:0;">
    <tr>
        <td align="center" bgcolor="{{ $background }}" style="background-color:{{ $background }};border-radius:6px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:{{ $width }}px;" arcsize="10%" stroke="f" fillcolor="{{ $background }}">
                <w:anchorlock/>
                <center style="color:{{ $color }};font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;">
                    {{ $label }}
                </center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}" target="_blank" style="display:inline-block;background-color:{{ $background }};color:{{ $color }};font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;line-height:44px;text-align:center;text-decoration:none;padding:0 24px;border-radius:6px;mso-hide:all;">
                {{ $label }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
@endif
