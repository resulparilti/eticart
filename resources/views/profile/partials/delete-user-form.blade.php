<p class="eticart-muted small mb-3">
    Hesabınız silindiğinde tüm verileriniz kalıcı olarak kaldırılır.
</p>

<button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
    Hesabı Sil
</button>

<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="{{ route('profile.destroy') }}">
                @csrf
                @method('delete')

                <div class="modal-header">
                    <h2 class="modal-title h5" id="deleteAccountModalLabel">Hesabı silmek istediğinize emin misiniz?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p class="eticart-muted">Onaylamak için şifrenizi girin.</p>
                    <label for="password" class="form-label">Şifre</label>
                    <input id="password" name="password" type="password" class="form-control @error('password', 'userDeletion') is-invalid @enderror" placeholder="Şifre">
                    @error('password', 'userDeletion')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-danger">Hesabı Sil</button>
                </div>
            </form>
        </div>
    </div>
</div>
