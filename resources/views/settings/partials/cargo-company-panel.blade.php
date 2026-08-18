@php
    $settings = $company->settings ?? [];
    $collapseId = 'cargo-company-'.$company->id;
    $headingId = 'cargo-heading-'.$company->id;
    $formId = 'cargo-settings-form';
@endphp

<div class="accordion-item">
    <h2 class="accordion-header" id="{{ $headingId }}">
        <button class="accordion-button {{ $company->is_active ? '' : 'collapsed' }}"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#{{ $collapseId }}"
                aria-expanded="{{ $company->is_active ? 'true' : 'false' }}"
                aria-controls="{{ $collapseId }}">
            <span class="me-2">{{ $company->name }}</span>
            <span class="badge text-bg-secondary me-1">{{ $company->provider_type }}</span>
            @if ($company->is_active)
                <span class="badge text-bg-success me-1">Aktif</span>
            @endif
            @if ($company->is_default)
                <span class="badge text-bg-primary">Varsayılan</span>
            @endif
        </button>
    </h2>
    <div id="{{ $collapseId }}"
         class="accordion-collapse collapse {{ $company->is_active ? 'show' : '' }}"
         aria-labelledby="{{ $headingId }}"
         data-bs-parent="#cargoCompaniesAccordion">
        <div class="accordion-body">
            <input type="hidden" form="{{ $formId }}" name="companies[{{ $index }}][id]" value="{{ $company->id }}">

            @if ($company->provider_type === 'yurtici')
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active"
                                id="yurtici-settings-tab-{{ $company->id }}"
                                data-bs-toggle="tab"
                                data-bs-target="#yurtici-settings-{{ $company->id }}"
                                type="button"
                                role="tab">
                            Ayarlar
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link"
                                id="yurtici-test-tab-{{ $company->id }}"
                                data-bs-toggle="tab"
                                data-bs-target="#yurtici-test-{{ $company->id }}"
                                type="button"
                                role="tab">
                            Test
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="yurtici-settings-{{ $company->id }}" role="tabpanel">
                        <div class="alert alert-info small">
                            Yurtiçi SOAP web servisi: gönderici/alıcı ödemeli için <strong>ayrı</strong> kullanıcı adı ve şifre kullanılır.
                            Müşteri kodu gönderi kaydına <code>custProdId</code> olarak yazılır.
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <h3 class="h6">Gönderici ödemeli</h3>
                                <div class="mb-2">
                                    <label class="form-label">Kullanıcı adı</label>
                                    <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][sender_username]" class="form-control"
                                           value="{{ old('companies.'.$index.'.sender_username', $settings['sender_username'] ?? $company->username) }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Şifre</label>
                                    <input type="password" form="{{ $formId }}" name="companies[{{ $index }}][sender_password]" class="form-control"
                                           placeholder="{{ $company->hasStoredCredential('password') ? 'Kayıtlı — değiştirmek için yazın' : 'Şifre' }}" autocomplete="new-password">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h3 class="h6">Alıcı ödemeli</h3>
                                <div class="mb-2">
                                    <label class="form-label">Kullanıcı adı</label>
                                    <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][receiver_username]" class="form-control"
                                           value="{{ old('companies.'.$index.'.receiver_username', $settings['receiver_username'] ?? $company->api_key) }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Şifre</label>
                                    <input type="password" form="{{ $formId }}" name="companies[{{ $index }}][receiver_password]" class="form-control"
                                           placeholder="{{ $company->hasStoredCredential('api_secret') ? 'Kayıtlı — değiştirmek için yazın' : 'Şifre' }}" autocomplete="new-password">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Müşteri kodu</label>
                                <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][customer_code]" class="form-control"
                                       value="{{ old('companies.'.$index.'.customer_code', $settings['customer_code'] ?? '') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Şube kodu</label>
                                <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][branch_code]" class="form-control"
                                       value="{{ old('companies.'.$index.'.branch_code', $settings['branch_code'] ?? '') }}">
                                <div class="form-text">Referans amaçlı saklanır.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Varsayılan ödeme tipi</label>
                                <select form="{{ $formId }}" name="companies[{{ $index }}][default_payment_type]" class="form-select">
                                    <option value="sender" @selected(($settings['default_payment_type'] ?? 'sender') === 'sender')>Gönderici ödemeli</option>
                                    <option value="receiver" @selected(($settings['default_payment_type'] ?? '') === 'receiver')>Alıcı ödemeli</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-warning small mb-0">
                                    <strong>Önemli:</strong> Yurtiçi <code>specialField1</code> alanı müşteri kodu değildir.
                                    Dolu gönderilirse <code>xx$xxx#</code> formatında olmalıdır.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">SOAP Endpoint (opsiyonel)</label>
                                <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][endpoint]" class="form-control"
                                       value="{{ old('companies.'.$index.'.endpoint', $settings['endpoint'] ?? 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices') }}">
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="yurtici-test-{{ $company->id }}" role="tabpanel">
                        @include('settings.partials.yurtici-test-panel', ['company' => $company, 'settings' => $settings])
                    </div>
                </div>
            @else
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">API Key</label>
                            <input type="password" form="{{ $formId }}" name="companies[{{ $index }}][api_key]" class="form-control" placeholder="Değiştirmek için yazın">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">API Secret</label>
                            <input type="password" form="{{ $formId }}" name="companies[{{ $index }}][api_secret]" class="form-control" placeholder="Değiştirmek için yazın">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Username</label>
                            <input type="text" form="{{ $formId }}" name="companies[{{ $index }}][username]" class="form-control" value="{{ $company->username }}">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Password</label>
                            <input type="password" form="{{ $formId }}" name="companies[{{ $index }}][password]" class="form-control" placeholder="Değiştirmek için yazın">
                        </div>
                    </div>
                </div>
            @endif

            <div class="border-top pt-3 mt-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" form="{{ $formId }}" type="checkbox" name="companies[{{ $index }}][is_active]" value="1" id="active_{{ $company->id }}" @checked($company->is_active)>
                    <label class="form-check-label" for="active_{{ $company->id }}">Aktif</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" form="{{ $formId }}" type="checkbox" name="companies[{{ $index }}][is_default]" value="1" id="default_{{ $company->id }}" @checked($company->is_default)>
                    <label class="form-check-label" for="default_{{ $company->id }}">Varsayılan</label>
                </div>
            </div>
        </div>
    </div>
</div>
