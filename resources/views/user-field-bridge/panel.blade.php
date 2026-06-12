@php $linkExpr = $linkExpr ?? "row.link"; @endphp
{{-- Shared WordPress user field bridge panel. Requires Alpine methods: fieldBridgeLoaded, loadFieldBridge, fieldPushReason, pushFieldBridge, disabledTitle. --}}
                        <div class="jd-field jd-field-gate">
                            <div class="jd-tool-head">
                                <div>
                                    <strong>Notion fields</strong>
                                    <div class="jd-row-sub">Load the Notion / WordPress field bridge before AI, Drive, or photo tools are shown.</div>
                                </div>
                                <button type="button" class="jd-mini jd-mini-indigo" :disabled="{!! $linkExpr !!}.field_busy" :title="{!! $linkExpr !!}.field_busy ? disabledTitle({!! $linkExpr !!}) : `Load Notion fields for this mapped journalist`" @click="loadFieldBridge({!! $linkExpr !!})">
                                    <span x-show="{!! $linkExpr !!}.field_busy===`load`" class="jd-spin"></span>
                                    <span x-text="{!! $linkExpr !!}.field_busy===`load` ? `Loading...` : (fieldBridgeLoaded({!! $linkExpr !!}) ? `Reload fields` : `Load Notion Fields`)"></span>
                                </button>
                            </div>
                            <div class="jd-inline-status" x-show="{!! $linkExpr !!}.field_message" :class="{!! $linkExpr !!}.field_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.field_message"></div>
                            <div class="jd-control-reason" x-show="{!! $linkExpr !!}.field_busy" x-text="disabledTitle({!! $linkExpr !!})"></div>
                            <template x-if="fieldBridgeLoaded({!! $linkExpr !!})">
                                <div class="jd-field-list">
                                    <template x-for="fieldRow in {!! $linkExpr !!}.field_rows" :key="fieldRow.key">
                                        <div class="jd-field-item">
                                            <div class="jd-field-item-head">
                                                <div>
                                                    <div class="jd-field-title" x-text="fieldRow.label"></div>
                                                    <div class="jd-field-key" x-text="fieldRow.notion_field || `No matching Notion field`"></div>
                                                </div>
                                                <div class="jd-field-actions">
                                                    <button type="button" class="jd-mini jd-mini-indigo" :disabled="!!fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || `Copy Notion to WordPress`" @click="pushFieldBridge({!! $linkExpr !!}, fieldRow, `notion_to_wp`)">Notion &gt; WP</button>
                                                    <button type="button" class="jd-mini jd-mini-ghost" :disabled="!!fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`) || `Copy WordPress to Notion`" @click="pushFieldBridge({!! $linkExpr !!}, fieldRow, `wp_to_notion`)">WP &gt; Notion</button>
                                                    <div class="jd-control-reason" x-show="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)" x-text="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)"></div>
                                                </div>
                                            </div>
                                            <div class="jd-field-compare">
                                                <div class="jd-field-panel">
                                                    <div class="jd-field-panel-label">Notion</div>
                                                    <div class="jd-field-val" x-text="fieldRow.notion_value || `Empty`"></div>
                                                </div>
                                                <div class="jd-field-panel">
                                                    <div class="jd-field-panel-label">WordPress</div>
                                                    <div class="jd-field-val" x-text="fieldRow.wp_value || `Empty`"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
