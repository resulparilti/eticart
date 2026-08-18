@props([
    'name' => 'body',
    'value' => '',
    'height' => '180px',
])

<div class="eticart-rich-editor" x-data="eticartRichEditor(@js($value))" x-init="init()">
    <div class="eticart-rich-editor__toolbar btn-toolbar flex-wrap gap-1 mb-2" role="toolbar">
        <select class="form-select form-select-sm" style="width:auto" @change="cmd('fontName', $event.target.value)">
            <option value="Arial">Arial</option>
            <option value="Georgia">Georgia</option>
            <option value="Tahoma">Tahoma</option>
            <option value="Verdana">Verdana</option>
            <option value="Courier New">Courier</option>
        </select>
        <select class="form-select form-select-sm" style="width:auto" @change="cmd('fontSize', $event.target.value)">
            <option value="2">Küçük</option>
            <option value="3" selected>Normal</option>
            <option value="4">Büyük</option>
            <option value="5">Daha büyük</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="cmd('bold')" title="Kalın"><i class="bi bi-type-bold"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="cmd('italic')" title="İtalik"><i class="bi bi-type-italic"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="cmd('underline')" title="Altı çizili"><i class="bi bi-type-underline"></i></button>
        <input type="color" class="form-control form-control-sm form-control-color" style="width:2.2rem" value="#111111" @input="cmd('foreColor', $event.target.value)" title="Renk">
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="addLink()" title="Link"><i class="bi bi-link-45deg"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="cmd('insertUnorderedList')" title="Liste"><i class="bi bi-list-ul"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="cmd('removeFormat')" title="Biçimi temizle"><i class="bi bi-eraser"></i></button>
    </div>
    <div
        class="eticart-rich-editor__surface form-control"
        contenteditable="true"
        x-ref="editor"
        @input="sync()"
        @blur="sync()"
        style="min-height: {{ $height }}; overflow:auto;"
    ></div>
    <input type="hidden" name="{{ $name }}" x-model="html">
</div>
