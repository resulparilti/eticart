<x-guest-layout>
    <h1 class="h5 mb-3">Şifremi Unuttum</h1>
    <p class="eticart-muted small mb-3">E-posta adresinizi girin, sıfırlama bağlantısı göndereceğiz.</p>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">E-posta</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus class="form-control @error('email') is-invalid @enderror">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Sıfırlama Bağlantısı Gönder</button>
        </div>
    </form>
</x-guest-layout>
