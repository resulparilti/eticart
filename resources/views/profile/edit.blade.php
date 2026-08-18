@extends('layouts.app')

@section('title', 'Profil')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Profil</h1>
        <p class="eticart-muted mb-0">Hesap bilgilerinizi güncelleyin.</p>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h5 mb-3">Profil Bilgileri</h2>
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 mb-3">
                <h2 class="h5 mb-3">Şifre Güncelle</h2>
                @include('profile.partials.update-password-form')
            </div>
            <div class="eticart-card p-3 border-danger">
                <h2 class="h5 mb-3 text-danger">Hesabı Sil</h2>
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
@endsection
