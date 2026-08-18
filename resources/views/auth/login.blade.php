<x-guest-layout>
    <h1 class="h5 mb-3">Giriş Yap</h1>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    @if (session('error'))
        <div class="alert alert-danger small">{{ session('error') }}</div>
    @endif

    <form method="POST" action="/login">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">E-posta</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="form-control @error('email') is-invalid @enderror">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Şifre</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" class="form-control @error('password') is-invalid @enderror">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3 form-check">
            <input id="remember_me" type="checkbox" class="form-check-input" name="remember">
            <label for="remember_me" class="form-check-label">Beni hatırla</label>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">Giriş Yap</button>
        </div>

        @if (Route::has('password.request'))
            <div class="text-center mt-3">
                <a class="small text-decoration-none" href="{{ route('password.request') }}">Şifremi unuttum</a>
            </div>
        @endif
    </form>
</x-guest-layout>
