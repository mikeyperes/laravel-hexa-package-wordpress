@once
<style>
    .vp-bridge-repeater-dump{border:1px solid #fde68a;background:#fffbeb;border-radius:9px;padding:9px;display:grid;gap:8px}
    .vp-bridge-repeater-dump-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .vp-bridge-repeater-dump-title{font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#92400e}
    .vp-bridge-repeater-dump-copy{font-size:10.5px;color:#78350f;line-height:1.35;margin-top:2px}
    .vp-bridge-repeater-dump textarea{min-height:86px;font-size:11.5px;line-height:1.45}
    .vp-bridge-repeater-dump-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .vp-bridge-repeater-dump-message{border:1px solid #fcd34d;background:#fff7ed;color:#78350f;border-radius:7px;padding:6px 8px;font-size:10.5px;font-weight:800;line-height:1.35}
    .vp-bridge-repeater-row-card.is-flash{animation:vpBridgeRepeaterFlash 1.4s ease}
    @keyframes vpBridgeRepeaterFlash{0%{box-shadow:0 0 0 0 rgba(16,185,129,.35);border-color:#10b981;background:#ecfdf5}100%{box-shadow:0 0 0 10px rgba(16,185,129,0);border-color:#e2e8f0;background:#fff}}
    .vp-local-notion-social.is-compact .vp-bridge-repeater-dump{padding:7px;gap:6px}
    .vp-local-notion-social.is-compact .vp-bridge-repeater-dump textarea{min-height:58px}
</style>
@endonce

<template x-if="notionBridgeIsRepeater(row)">
    <div class="vp-bridge-repeater" data-hexa-acf-repeater-bridge :data-structure-key="row.structure_key || row.wp_field || row.acf_field || ''">
        <div class="vp-bridge-repeater-head">
            <span x-text="(row.structure && row.structure.label) || row.wp_label || `Repeater`"></span>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                <span class="hx-field-generic-marker" data-hexa-generic-marker title="Reusable generic ACF repeater Blade UI">Generic</span>
                <button type="button" class="vp-btn vp-btn-primary" x-on:click="addNotionBridgeRepeaterRow(row)" data-hexa-acf-repeater-add>Add row</button>
            </div>
        </div>
        <div class="vp-bridge-repeater-help" x-text="notionBridgeRepeaterHelp(row)"></div>
        <div class="vp-bridge-repeater-dump" x-show="notionBridgeRepeaterSupportsDump(row)" x-cloak>
            <div class="vp-bridge-repeater-dump-head">
                <div>
                    <div class="vp-bridge-repeater-dump-title">Unstructured to structured</div>
                    <div class="vp-bridge-repeater-dump-copy">Use the Notion source as a temporary dump, build proposed rows, then approve individual rows into the local repeater.</div>
                </div>
                <span class="hx-field-generic-marker" data-hexa-generic-marker title="SFPF-compatible generic repeater workflow">Generic</span>
            </div>
            <textarea class="vp-textarea" :value="notionBridgeRepeaterDump(row)" x-on:input="setNotionBridgeRepeaterDump(row, $event.target.value)" placeholder="Paste unstructured Notion source text or URLs..."></textarea>
            <div class="vp-bridge-repeater-dump-actions">
                <button type="button" class="hx-field-btn" x-on:click="pullNotionBridgeRepeaterDump(row)" :disabled="!String(notionBridgeDraft(row, `notion`) || ``).trim() || isBusy(notionBridgeActionKey(row, `build-dump`))">Pull Notion source</button>
                <button type="button" class="hx-field-btn hx-field-btn-warn" x-on:click="buildNotionBridgeRepeaterRowsFromDump(row)" :disabled="!String(notionBridgeRepeaterDump(row) || ``).trim() || isBusy(notionBridgeActionKey(row, `build-dump`))"><span x-show="isBusy(notionBridgeActionKey(row, `build-dump`))" class="hx-field-spin"></span><span>Build proposed rows</span></button>
                <button type="button" class="hx-field-btn" x-on:click="clearNotionBridgeRepeaterDump(row)" :disabled="isBusy(notionBridgeActionKey(row, `build-dump`))">Clear dump</button>
            </div>
            <div class="vp-bridge-repeater-dump-message" x-show="notionBridgeRepeaterDumpMessage(row)" x-text="notionBridgeRepeaterDumpMessage(row)" x-cloak></div>
        </div>
        <template x-if="!notionBridgeRepeaterRows(row).length">
            <div class="vp-bridge-repeater-empty">No local repeater rows yet. Add a row, build proposed rows, or copy the Notion value to Local.</div>
        </template>
        <div class="vp-bridge-repeater-rows">
            <template x-for="(item, rowIndex) in notionBridgeRepeaterRows(row)" :key="notionBridgeKey(row) + `-rep-` + rowIndex">
                <div class="vp-bridge-repeater-row-card" :class="notionBridgeRepeaterRowFlash(row, rowIndex) ? `is-flash` : ``" data-hexa-acf-repeater-row>
                    <div class="vp-bridge-repeater-row-head">
                        <span class="vp-bridge-repeater-row-title" x-text="((row.structure && row.structure.label) || row.label || `Row`) + ` #` + (rowIndex + 1)"></span>
                        <button type="button" class="vp-btn vp-btn-danger" x-on:click="removeNotionBridgeRepeaterRow(row, rowIndex)">Remove</button>
                    </div>
                    <div class="vp-bridge-repeater-row">
                        <template x-for="column in notionBridgeRepeaterColumns(row)" :key="column.key">
                            <label class="vp-bridge-repeater-cell" :data-column-key="column.key">
                                <span x-text="column.label"></span>
                                <template x-if="notionBridgeColumnUsesTextarea(column)">
                                    <textarea class="vp-textarea vp-bridge-repeater-input" :value="notionBridgeRepeaterCell(row, rowIndex, column)" x-on:input="setNotionBridgeRepeaterCell(row, rowIndex, column, $event.target.value)"></textarea>
                                </template>
                                <template x-if="!notionBridgeColumnUsesTextarea(column)">
                                    <div>
                                        <input class="vp-input vp-bridge-repeater-input" :type="notionBridgeColumnInputType(column)" :value="notionBridgeRepeaterCell(row, rowIndex, column)" x-on:input="setNotionBridgeRepeaterCell(row, rowIndex, column, $event.target.value)">
                                        <template x-if="notionBridgeColumnInputType(column) === `url` && notionBridgeRepeaterCell(row, rowIndex, column)">
                                            <div class="vp-link-hints vp-link-hints-compact">
                                                <div class="vp-link-disclaimer">Link</div>
                                                <a class="vp-link-pill" :href="notionBridgeRepeaterCell(row, rowIndex, column)" target="_blank" rel="noopener noreferrer">
                                                    <span x-text="notionBridgeRepeaterCell(row, rowIndex, column)"></span>
                                                    <span class="vp-link-arrow">Open -&gt;</span>
                                                </a>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </label>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
