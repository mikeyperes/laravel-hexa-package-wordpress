@php $linkExpr = $linkExpr ?? "row.link"; @endphp
{{-- Shared WordPress user field bridge panel using the SFPF audit field-workspace interaction model. --}}
                        <div class="jd-field jd-field-gate jd-sfpf-bridge">
                            <div class="jd-tool-head jd-sfpf-bridge-head">
                                <div>
                                    <strong>Notion / WordPress field bridge</strong>
                                    <div class="jd-row-sub">Load live Notion and WordPress values, then use the same transfer, AI proposal, and manual-save workflow as SFPF.</div>
                                </div>
                                <button type="button" class="jd-mini jd-mini-indigo" :disabled="{!! $linkExpr !!}.field_busy" :title="{!! $linkExpr !!}.field_busy ? disabledTitle({!! $linkExpr !!}) : `Load Notion fields for this mapped journalist`" @click.stop="loadFieldBridge({!! $linkExpr !!})" data-sfpf-action="load-journalist-field-bridge">
                                    <span x-show="{!! $linkExpr !!}.field_busy===`load`" class="jd-spin"></span>
                                    <span x-text="{!! $linkExpr !!}.field_busy===`load` ? `Loading` : (fieldBridgeLoaded({!! $linkExpr !!}) ? `Reload fields` : `Load Notion Fields`)"></span>
                                </button>
                            </div>
                            <div class="jd-inline-status" x-show="{!! $linkExpr !!}.field_message" :class="{!! $linkExpr !!}.field_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.field_message"></div>
                            <div class="jd-control-reason" x-show="{!! $linkExpr !!}.field_busy" x-text="disabledTitle({!! $linkExpr !!})"></div>
                            <template x-if="fieldBridgeLoaded({!! $linkExpr !!}) && {!! $linkExpr !!}.field_busy !== &quot;load&quot;">
                                <div class="jd-sfpf-workspace">
                                    <div class="jd-profile-photo-card jd-bridge-profile-photo-card" x-show="bridgePhotoRows({!! $linkExpr !!}).length" x-init="$nextTick(() => { if(!{!! $linkExpr !!}.photo_scanned_once && bridgePhotoRows({!! $linkExpr !!}).length){ {!! $linkExpr !!}.photo_scanned_once = true; scanPhotos({!! $linkExpr !!}); } })" data-journalist-profile-photo-bridge>
                                        <div class="jd-profile-photo-head">
                                            <div><div class="jd-profile-photo-title">Profile photo</div><div class="jd-profile-photo-note">Edit the Notion photo sources, paste image or Drive URLs, then pick any image to set the WordPress profile photo. Drive folders load into the candidate gallery on Scan.</div></div>
                                            <div class="jd-gallery-actions">
                                                @if(Route::has('google-drive.photo-renamer'))
                                                    <a href="{{ route('google-drive.photo-renamer') }}" target="_blank" rel="noopener" class="jd-mini jd-mini-ghost" @click.stop>Photo renamer <span aria-hidden="true">&#8599;</span></a>
                                                @else
                                                    <a href="/google-drive/photo-renamer" target="_blank" rel="noopener" class="jd-mini jd-mini-ghost" @click.stop>Photo renamer <span aria-hidden="true">&#8599;</span></a>
                                                @endif
                                                <button type="button" class="jd-mini jd-mini-ghost" :disabled="photoScanBusy({!! $linkExpr !!})" @click.stop="scanPhotos({!! $linkExpr !!})" data-journalist-action="load-bridge-folder-photos"><span x-show="photoScanBusy({!! $linkExpr !!})" class="jd-spin jd-spin-dark"></span><span x-text="photoScanBusy({!! $linkExpr !!}) ? `Scanning...` : `Scan all sources`"></span></button>
                                                <label class="jd-mini jd-mini-indigo" :style="photoMutationBusy({!! $linkExpr !!}) ? `opacity:.55;cursor:default` : ``" @click.stop data-journalist-action="upload-bridge-profile-photo"><span x-show="{!! $linkExpr !!}.photo_busy===`upload`" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.photo_busy===`upload` ? `Uploading...` : `Upload`"></span><input type="file" accept="image/*" style="display:none" :disabled="photoMutationBusy({!! $linkExpr !!})" @change="uploadPhotoFile({!! $linkExpr !!}, $event, bridgeDefaultPhotoField({!! $linkExpr !!}))"></label>
                                            </div>
                                        </div>
                                        <div style="display:grid;grid-template-columns:minmax(160px,200px) minmax(0,1fr);gap:14px;align-items:start;">
                                            <div style="border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:10px;">
                                                <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">On WordPress now</div>
                                                <div style="height:140px;border-radius:10px;background:#f8fafc;border:1px solid #eef2f7;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                                                    <template x-if="profilePhotoUrl({!! $linkExpr !!})"><img :src="profilePhotoUrl({!! $linkExpr !!})" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"></template>
                                                    <template x-if="!profilePhotoUrl({!! $linkExpr !!})"><div style="width:64px;height:64px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-weight:700;font-size:20px;display:flex;align-items:center;justify-content:center;" x-text="profilePhotoInitials({!! $linkExpr !!})"></div></template>
                                                </div>
                                                <div style="margin-top:8px;font-size:11px;color:#64748b;line-height:1.5;"><span x-text="profilePhotoUrl({!! $linkExpr !!}) ? `Profile photo set` : `No profile photo set`"></span><br><span x-text="profilePhotoSource({!! $linkExpr !!})"></span></div>
                                            </div>
                                            <div style="display:flex;flex-direction:column;gap:7px;">
                                                <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;">Notion photo sources</div>
                                                <template x-for="fieldRow in bridgePhotoRows({!! $linkExpr !!})" :key="fieldRow.key">
                                                    <div style="display:flex;gap:11px;align-items:flex-start;border:1px solid #e5e7eb;border-radius:10px;padding:9px 10px;background:#fff;">
                                                        <template x-if="bridgePhotoUrl(fieldRow) && !(bridgePhotoUrl(fieldRow)||``).includes(`/folders/`)"><div style="width:46px;height:46px;border-radius:8px;background:#f1f5f9;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;margin-top:17px;"><img :src="bridgePhotoUrl(fieldRow)" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;" x-on:error="$el.style.display=`none`"></div></template>
                                                        <div style="flex:1;min-width:0;">
                                                            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:3px;" x-text="fieldRow.notion_label || fieldRow.notion_field || fieldRow.label"></div>
                                                            <input type="text" x-model="fieldRow.notion_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `notion`, $event.target.value)" placeholder="Paste an image or Google Drive URL..." style="width:100%;border:1px solid #e2e8f0;border-radius:7px;padding:6px 9px;font-size:12px;color:#0f172a;background:#fff;">
                                                            <template x-if="(fieldRow.notion_value || ``).trim().toLowerCase().startsWith(`http`)">
                                                                <a :href="(fieldRow.notion_value || ``).trim()" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;color:#4338ca;text-decoration:underline;text-underline-offset:2px;word-break:break-all;max-width:100%;line-height:1.4;"><span x-text="(fieldRow.notion_value || ``).trim()"></span><span style="flex-shrink:0;font-size:12px;">&#8599;</span></a>
                                                            </template>
                                                            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                                                <button type="button" class="jd-mini jd-mini-ghost" :disabled="!canSaveNotionRow({!! $linkExpr !!}, fieldRow)" @click.stop="saveBridgeField({!! $linkExpr !!}, fieldRow, `notion`)" title="Save this value back to the Notion field"><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `saving`" class="jd-spin jd-spin-dark"></span><span x-text="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `saving` ? `Saving...` : `Save`"></span></button>
                                                                <button type="button" class="jd-mini jd-mini-indigo" x-show="photoFieldWantsFolder(fieldRow)" :disabled="{!! $linkExpr !!}.gallery_busy" @click.stop="createGalleryForPhotoField({!! $linkExpr !!}, fieldRow)" data-journalist-action="create-photo-source-folder" title="Create a Drive folder in the configured journalist parent folder and save it to this Notion photo source"><span x-show="photoFieldCreateBusy({!! $linkExpr !!}, fieldRow)" class="jd-spin"></span><span x-text="photoFieldCreateBusy({!! $linkExpr !!}, fieldRow) ? `Creating...` : photoFieldCreateFolderLabel({!! $linkExpr !!})"></span></button>
                                                                <button type="button" class="jd-mini jd-mini-ok" x-show="bridgePhotoUrl(fieldRow) && !(bridgePhotoUrl(fieldRow)||``).includes(`/folders/`)" :disabled="photoMutationBusy({!! $linkExpr !!})" @click.stop="importPhoto({!! $linkExpr !!}, bridgePhotoCandidate(fieldRow))" title="Set this image as the WordPress profile photo"><span x-show="{!! $linkExpr !!}.photo_busy===fieldRow.key" class="jd-spin"></span><span>Set as photo</span></button><button type="button" class="jd-mini jd-mini-ghost" x-show="(bridgePhotoUrl(fieldRow)||``).includes(`/folders/`)" :disabled="{!! $linkExpr !!}.photo_busy === `scan` || photoRowScanBusy({!! $linkExpr !!}, fieldRow)" @click.stop="scanPhotos({!! $linkExpr !!}, fieldRow)" title="This is a Google Drive folder, not a single image - scan it to load its photos into the candidate gallery below, then pick one."><span x-show="photoRowScanBusy({!! $linkExpr !!}, fieldRow)" class="jd-spin jd-spin-dark"></span><span x-text="photoRowScanBusy({!! $linkExpr !!}, fieldRow) ? `Scanning` : `Scan folder`"></span></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px;font-size:11px;color:#64748b;">
                                                    <span style="font-weight:700;color:#475569;">Drive folder:</span>
                                                    <template x-if="galleryFolderUrl({!! $linkExpr !!})"><a :href="galleryFolderUrl({!! $linkExpr !!})" target="_blank" rel="noopener" class="jd-link-lite" style="word-break:break-all;flex:1;min-width:120px;" x-text="galleryFolderUrl({!! $linkExpr !!})"></a></template>
                                                    <template x-if="!galleryFolderUrl({!! $linkExpr !!})"><span style="color:#94a3b8;flex:1;">none saved</span></template>
                                                    <button type="button" class="jd-mini jd-mini-ghost" :disabled="{!! $linkExpr !!}.gallery_busy" @click.stop="detectGallery({!! $linkExpr !!})"><span x-show="{!! $linkExpr !!}.gallery_busy===`find`" class="jd-spin jd-spin-dark"></span><span x-text="{!! $linkExpr !!}.gallery_busy===`find` ? `Detecting...` : `Detect`"></span></button>
                                                    <button type="button" class="jd-mini jd-mini-indigo" :disabled="{!! $linkExpr !!}.gallery_busy" @click.stop="createGallery({!! $linkExpr !!})"><span x-show="{!! $linkExpr !!}.gallery_busy===`create`" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.gallery_busy===`create` ? `Creating...` : `Create`"></span></button>
                                                </div>
                                                <div class="jd-inline-status" x-show="{!! $linkExpr !!}.gallery_message" x-text="{!! $linkExpr !!}.gallery_message"></div>
                                            </div>
                                        </div>
                                        <div x-show="photoCandidates({!! $linkExpr !!}).length" style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:10px;">
                                            <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;">Candidates &mdash; pick one for the WordPress profile photo</div>
                                            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;">
                                                <template x-for="candidate in photoCandidates({!! $linkExpr !!})" :key="candidate.key || candidate.url">
                                                    <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff;display:flex;flex-direction:column;">
                                                        <div x-data="{ld:false}" style="position:relative;height:130px;background:#f1f5f9;overflow:hidden;"><span x-show="!ld" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;"><span class="jd-spin jd-spin-dark" style="width:20px;height:20px;"></span></span><img :src="candidate.thumb_url || candidate.url" x-on:load="ld=true" x-on:error="if(candidate.thumb_url && candidate.url && !$el.dataset.fallback){$el.dataset.fallback=`1`;$el.src=candidate.url}else{ld=true;$el.style.display=`none`}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"><span style="position:absolute;top:5px;left:5px;background:rgba(15,23,42,.8);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em;max-width:88%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="candidate.source_property || candidate.source_type || `source`"></span></div>
                                                        <div style="padding:6px 7px;font-size:10px;color:#64748b;line-height:1.35;min-width:0;"><div style="font-weight:700;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="candidate.name || `Image`"></div><div x-text="photoCandidateMeta(candidate)"></div></div>
                                                        <button type="button" class="jd-mini jd-mini-ok" style="margin:0 7px 7px;justify-content:center;" :disabled="photoMutationBusy({!! $linkExpr !!})" @click.stop="importPhoto({!! $linkExpr !!}, candidate)"><span x-show="{!! $linkExpr !!}.photo_busy===(candidate.key || candidate.url)" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.photo_busy===(candidate.key || candidate.url) ? `Setting` : `Use as profile photo`"></span></button>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                        <div class="jd-inline-status" x-show="{!! $linkExpr !!}.photo_message" x-text="{!! $linkExpr !!}.photo_message" style="margin-top:8px;"></div>
                                        <div class="jd-photo-step-log" x-show="photoSteps({!! $linkExpr !!}).length" x-cloak>
                                            <template x-for="step in photoSteps({!! $linkExpr !!})" :key="step.label">
                                                <div class="jd-photo-step" :class="photoStepClass(step)">
                                                    <span class="jd-photo-step-icon" aria-hidden="true">
                                                        <span x-show="step.state==&quot;working&quot;" class="jd-spin jd-spin-dark" style="width:12px;height:12px;"></span>
                                                        <span x-show="step.state!=&quot;working&quot;" x-text="photoStepIcon(step)"></span>
                                                    </span>
                                                    <span class="jd-photo-step-label" x-text="step.label"></span>
                                                    <span class="jd-photo-step-message" x-show="step.message" x-text="step.message"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="jd-sfpf-field-list">
                                        <div class="jd-sfpf-field jd-sfpf-control-field" x-data="{ open: true }" @click.stop data-journalist-role-bridge>
                                            <div class="jd-sfpf-field-head">
                                                <div class="jd-sfpf-field-title-block">
                                                    <div>
                                                        <div class="jd-sfpf-field-label">WordPress Role</div>
                                                        <div class="jd-sfpf-field-mapline"><span>Account control</span><b>role</b><span>→ WordPress</span><b>role</b></div>
                                                    </div>
                                                </div>
                                                <div class="jd-sfpf-field-meta">
                                                    <span x-show="{!! $linkExpr !!}.role_busy || {!! $linkExpr !!}.role_message" class="jd-sfpf-pill jd-sfpf-status-pill" :class="{!! $linkExpr !!}.role_busy ? `is-saving` : ({!! $linkExpr !!}.role_error ? `is-error` : `is-success`)"><span x-show="{!! $linkExpr !!}.role_busy" class="jd-spin jd-spin-dark"></span><span x-show="!{!! $linkExpr !!}.role_busy" x-text="{!! $linkExpr !!}.role_error ? `×` : `✓`"></span><span x-text="{!! $linkExpr !!}.role_busy ? `Saving` : ({!! $linkExpr !!}.role_error ? `Failed` : `Saved`)"></span></span>
                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" x-text="open ? `Collapse` : `Expand`"></button>
                                                </div>
                                            </div>
                                            <div x-show="!open" x-cloak class="jd-sfpf-collapsed-summary">
                                                <div><b>WordPress Role</b><span> · role</span></div>
                                                <div><b>WordPress:</b> <span x-text="`role = ` + ({!! $linkExpr !!}.role || `Empty`)"></span></div>
                                                <div x-show="{!! $linkExpr !!}.role_message" x-text="{!! $linkExpr !!}.role_message"></div>
                                            </div>
                                            <div x-show="open" x-cloak class="jd-sfpf-field-body">
                                                <div class="jd-sfpf-field-row">
                                                    <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                        <div class="jd-sfpf-side-label"><span>ACCOUNT CONTROL</span><b>role</b></div>
                                                        <input type="text" class="jd-cu-input" value="Managed directly on the WordPress user" readonly>
                                                    </div>
                                                    <div class="jd-sfpf-arrow">→</div>
                                                    <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                        <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b>role</b></div>
                                                        <select class="jd-cu-input jd-cu-select" x-model="{!! $linkExpr !!}.role" x-effect="$nextTick(() => { $el.value = {!! $linkExpr !!}.role || {!! $linkExpr !!}.role_current || `contributor`; })" :disabled="{!! $linkExpr !!}.role_busy" @change="{!! $linkExpr !!}.role_message = ``; {!! $linkExpr !!}.role_error = false">
                                                            <option value="administrator">Administrator</option>
                                                            <option value="editor">Editor</option>
                                                            <option value="author">Author</option>
                                                            <option value="contributor">Contributor</option>
                                                            <option value="subscriber">Subscriber</option>
                                                        </select>
                                                        <div style="margin-top:8px;font-size:11px;color:#64748b;line-height:1.5;">
                                                            <span>Current WordPress role: </span><strong style="color:#334155;" x-text="roleDisplayLabel({!! $linkExpr !!}.role_current || {!! $linkExpr !!}.role)"></strong>
                                                            <span x-show="roleChanged({!! $linkExpr !!})"> · Selected change: <strong style="color:#3730a3;" x-text="roleDisplayLabel({!! $linkExpr !!}.role)"></strong></span>
                                                        </div>
                                                        <div class="jd-sfpf-side-actions">
                                                            <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :disabled="{!! $linkExpr !!}.role_busy || !roleChanged({!! $linkExpr !!})" @click.stop="updateWordPressRole({!! $linkExpr !!})" :title="roleChanged({!! $linkExpr !!}) ? `Save the selected WordPress role` : `The selected role is already current`">
                                                                <span x-show="{!! $linkExpr !!}.role_busy" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.role_busy ? `Saving role...` : `Save role`"></span>
                                                            </button>
                                                        </div>
                                                        <div class="jd-username-status" :class="{!! $linkExpr !!}.role_error ? `is-error` : ``" x-show="{!! $linkExpr !!}.role_message" x-text="{!! $linkExpr !!}.role_message"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="jd-sfpf-field jd-sfpf-control-field" x-show="{!! $linkExpr !!}.wp_user_id" x-data="{ open: true }" @click.stop data-journalist-username-recreate>
                                            <div class="jd-sfpf-field-head">
                                                <div class="jd-sfpf-field-title-block">
                                                    <div>
                                                        <div class="jd-sfpf-field-label">WordPress Username</div>
                                                        <div class="jd-sfpf-field-mapline"><span>Suggested</span><b>username</b><span>→ WordPress</span><b>user_login</b></div>
                                                    </div>
                                                </div>
                                                <div class="jd-sfpf-field-meta">
                                                    <span x-show="{!! $linkExpr !!}.username_message" class="jd-sfpf-pill jd-sfpf-status-pill" :class="{!! $linkExpr !!}.username_error ? `is-error` : ``"><span x-text="{!! $linkExpr !!}.username_error ? `×` : `✓`"></span><span x-text="{!! $linkExpr !!}.username_error ? `Failed` : `Saved`"></span></span>
                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" x-text="open ? `Collapse` : `Expand`"></button>
                                                </div>
                                            </div>
                                            <div x-show="!open" x-cloak class="jd-sfpf-collapsed-summary">
                                                <div><b>WordPress Username</b><span> · user_login</span></div>
                                                <div><b>Suggested:</b> <span x-text="linkUsernameSuggestion({!! $linkExpr !!}) || `none`"></span></div>
                                                <div><b>WordPress:</b> <span x-text="`user_login = ` + ({!! $linkExpr !!}.wp_login || `Empty`)"></span></div>
                                                <div x-show="{!! $linkExpr !!}.username_message" x-text="{!! $linkExpr !!}.username_message"></div>
                                            </div>
                                            <div x-show="open" x-cloak class="jd-sfpf-field-body">
                                                <div class="jd-sfpf-field-row">
                                                    <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                        <div class="jd-sfpf-side-label"><span>SUGGESTED</span><b>username</b></div>
                                                        <input type="text" class="jd-cu-input" :value="linkUsernameSuggestion({!! $linkExpr !!}) || `none`" readonly>
                                                    </div>
                                                    <div class="jd-sfpf-arrow">→</div>
                                                    <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                        <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b>user_login</b></div>
                                                        <input class="jd-cu-input" x-model="{!! $linkExpr !!}.username_new" :disabled="{!! $linkExpr !!}.username_busy" @input="{!! $linkExpr !!}.username_message = ``; {!! $linkExpr !!}.username_error = false" autocomplete="off">
                                                        <div class="jd-sfpf-side-actions">
                                                            <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" :disabled="{!! $linkExpr !!}.username_busy || !linkUsernameSuggestion({!! $linkExpr !!})" @click.stop="applyLinkUsernameSuggestion({!! $linkExpr !!})">Apply suggested</button>
                                                            <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :disabled="{!! $linkExpr !!}.username_busy || !linkUsernameChanged({!! $linkExpr !!})" @click.stop="recreateWordPressUsername({!! $linkExpr !!})">
                                                                <span x-show="{!! $linkExpr !!}.username_busy" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.username_busy ? `Replacing...` : `Recreate user with username`"></span>
                                                            </button>
                                                        </div>
                                                        <div class="jd-username-help">Changing this creates a replacement WordPress user, reassigns content, then deletes the old user.</div>
                                                        <div class="jd-username-status" :class="{!! $linkExpr !!}.username_error ? `is-error` : ``" x-show="{!! $linkExpr !!}.username_message" x-text="{!! $linkExpr !!}.username_message"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <template x-for="fieldRow in bridgeVisibleRows({!! $linkExpr !!}).filter(r => !r.is_photo_bridge)" :key="fieldRow.key">
                                            <div class="jd-sfpf-field" :class="fieldCardClass(fieldRow)" x-data="{ open: !fieldMarkedDone(fieldRow) }" x-effect="if(fieldMarkedDone(fieldRow)){ open=false } else if(fieldRow.save_status === `error`){ open=true }">
                                                <div class="jd-sfpf-field-head">
                                                    <div class="jd-sfpf-field-title-block">
                                                        <span x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-done-icon">✓</span>
                                                        <div>
                                                            <div class="jd-sfpf-field-label" x-text="fieldRow.label"></div>
                                                            <div class="jd-sfpf-field-mapline"><span>Notion</span><b x-text="fieldRow.notion_field || `—`"></b><span>→ WordPress</span><b x-text="fieldRow.wp_field || `—`"></b></div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-sfpf-field-meta">
                                                        <button type="button" x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-pill jd-sfpf-pill-done" title="Move this field back to review" @click.stop="unmarkBridgeFieldDone(fieldRow); open=true" data-sfpf-action="reopen-field">Completed <span class="jd-sfpf-pill-done-x" aria-hidden="true">&times;</span></button>
                                                        <span x-show="fieldValuesEquivalent(fieldRow) && !fieldRow.save_status" class="jd-sfpf-pill jd-sfpf-pill-sync">in sync</span>
                                                        <span x-show="fieldRow.save_status" class="jd-sfpf-pill jd-sfpf-status-pill" :class="fieldSaveStatusClass(fieldRow)"><span class="jd-spin jd-spin-dark" x-show="fieldRow.save_status === `saving`"></span><span x-show="fieldRow.save_status !== `saving`" x-text="fieldSaveStatusIcon(fieldRow)"></span><span x-text="fieldSaveStatusLabel(fieldRow)"></span></span>
                                                        <button type="button" x-show="!fieldMarkedDone(fieldRow)" class="jd-sfpf-mini jd-sfpf-mini-done" @click.stop="markBridgeFieldDone(fieldRow); open=false" data-sfpf-action="mark-field-completed">Mark completed</button>
                                                        <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" x-text="open ? `Collapse` : `Expand`"></button>
                                                    </div>
                                                </div>
                                                <div x-show="!open" x-cloak class="jd-sfpf-collapsed-summary">
                                                    <div><b x-text="fieldRow.label"></b><span x-text="` · ` + (fieldRow.wp_field || `WordPress field`)"></span></div>
                                                    <div><b>Notion:</b> <span x-text="(fieldRow.notion_field || `—`) + ` = ` + fieldValuePreview(fieldRow.notion_value)"></span></div>
                                                    <div><b>WordPress:</b> <span x-text="(fieldRow.wp_field || `—`) + ` = ` + fieldValuePreview(fieldDisplayValue(fieldRow, fieldRow.wp_value, `wordpress`))"></span></div>
                                                    <div x-show="fieldRow.save_status || fieldRow.save_message" x-cloak class="jd-sfpf-collapsed-live" :class="fieldRow.save_status || ``">
                                                        <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                        <span x-show="fieldRow.save_status === `error`" class="jd-field-save-x">&#10005;</span>
                                                        <span x-show="fieldRow.save_status && fieldRow.save_status !== `saving` && fieldRow.save_status !== `error`" class="jd-field-save-ok">&#10003;</span>
                                                        <b x-text="fieldSaveStatusLabel(fieldRow) || `Status`"></b>
                                                        <span x-text="fieldRow.save_message || (fieldRow.save_status === `saving` ? `Saving...` : (fieldRow.save_status === `error` ? `Failed` : `Saved`))"></span>
                                                    </div>
                                                    <template x-if="latestFieldActivity({!! $linkExpr !!}, fieldRow)">
                                                        <div class="jd-sfpf-collapsed-activity" :class="`is-${latestFieldActivity({!! $linkExpr !!}, fieldRow).type}`">
                                                            <span class="jd-activity-dot"></span>
                                                            <span x-text="latestFieldActivity({!! $linkExpr !!}, fieldRow).message"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div x-show="open" x-cloak class="jd-sfpf-field-body">
                                                    <div class="jd-sfpf-field-row">
                                                        <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                            <div class="jd-sfpf-side-label"><span>NOTION</span><b x-text="fieldRow.notion_field || `—`"></b></div>
                                                            <textarea x-model="fieldRow.notion_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `notion`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.notion_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `notion`)" ></textarea>
                                                            <div class="jd-sfpf-links" x-show="fieldValueLinks(fieldRow, `notion`).length">
                                                                <template x-for="url in fieldValueLinks(fieldRow, `notion`)" :key="`n-` + url"><a :href="url" target="_blank" rel="noopener" class="jd-link" x-text="url"></a></template>
                                                            </div>
                                                            <div class="jd-sfpf-side-actions">
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-notion" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`)" :disabled="!canSaveNotionRow({!! $linkExpr !!}, fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`) || `Save the current Notion-side input to Notion`" @click.stop="saveBridgeField({!! $linkExpr !!}, fieldRow, `notion`)" data-sfpf-action="manual-save-to-notion">
                                                                    <span class="jd-spin jd-spin-dark" x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `saving`"></span><span>Save to Notion</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `success`">✓</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `error`">×</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="jd-sfpf-arrow">→</div>
                                                        <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                            <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b x-text="fieldRow.wp_field || `—`"></b></div>
                                                            <template x-if="fieldControlKind(fieldRow) !== `boolean`">
                                                                <textarea x-model="fieldRow.wp_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `wordpress`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.wp_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `wordpress`)" ></textarea>
                                                            </template>
                                                            <template x-if="fieldControlKind(fieldRow) === `boolean`">
                                                                <div class="jd-boolean-toggle" role="group" :aria-label="(fieldRow.label || fieldRow.wp_field || `Boolean field`) + ` WordPress value`">
                                                                    <button type="button" :class="fieldBooleanValue(fieldRow.wp_value) ? `is-active` : ``" @click.stop="setBooleanFieldValue({!! $linkExpr !!}, fieldRow, true)">Yes</button>
                                                                    <button type="button" :class="!fieldBooleanValue(fieldRow.wp_value) ? `is-active` : ``" @click.stop="setBooleanFieldValue({!! $linkExpr !!}, fieldRow, false)">No</button>
                                                                    <span x-text="`Currently ` + fieldBooleanLabel(fieldRow.wp_value)"></span>
                                                                </div>
                                                            </template>
                                                            <div class="jd-sfpf-links" x-show="fieldValueLinks(fieldRow, `wordpress`).length">
                                                                <template x-for="url in fieldValueLinks(fieldRow, `wordpress`)" :key="`w-` + url"><a :href="url" target="_blank" rel="noopener" class="jd-link" x-text="url"></a></template>
                                                            </div>
                                                            <div x-show="fieldProposedValue(fieldRow)" class="jd-sfpf-proposal" data-sfpf-field-proposal>
                                                                <div class="jd-sfpf-proposal-top"><span x-text="fieldProposalLabel(fieldRow)"></span><button type="button" @click.stop="denyFieldProposal(fieldRow)" title="Dismiss proposal">×</button></div>
                                                                <div class="jd-sfpf-proposal-value" x-text="fieldProposalDisplayValue(fieldRow)"></div>
                                                                <div class="jd-sfpf-proposal-why" x-show="fieldProposalReason(fieldRow)" x-text="fieldProposalReason(fieldRow)"></div>
                                                                <div class="jd-sfpf-proposal-actions">
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `proposal_approve:wordpress`)" :disabled="fieldTargetBusy({!! $linkExpr !!}, fieldRow, `wordpress`) || !fieldProposedValue(fieldRow)" @click.stop="approveFieldProposal({!! $linkExpr !!}, fieldRow)" data-sfpf-action="approve-field-proposal">Approve</button>
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-danger" :disabled="fieldTargetBusy({!! $linkExpr !!}, fieldRow, `wordpress`)" @click.stop="denyFieldProposal(fieldRow)">Deny</button>
                                                                </div>
                                                            </div>
                                                            <div class="jd-sfpf-side-actions">
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`)" :disabled="!canSaveWordPressRow({!! $linkExpr !!}, fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || `Save the current WordPress-side input to WordPress`" @click.stop="saveBridgeField({!! $linkExpr !!}, fieldRow, `wordpress`)" data-sfpf-action="manual-save-to-wordpress">
                                                                    <span class="jd-spin" x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `saving`"></span><span>Save to WordPress</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `success`">✓</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `error`">×</span>
                                                                </button>
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-rich" x-show="fieldControlKind(fieldRow) === `textarea`" @click.stop="toggleBridgeRichEditor($event)">Rich editor</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-bridge-photo-row" x-show="fieldRow.is_photo_bridge" data-journalist-photo-bridge-row>
                                                        <div class="jd-photo-card" :class="bridgePhotoUrl(fieldRow) ? `` : `is-empty`">
                                                            <div class="jd-photo-thumb-wrap"><template x-if="bridgePhotoUrl(fieldRow)"><img class="jd-photo-thumb" :src="bridgePhotoUrl(fieldRow)" alt="" loading="lazy" x-on:error="$el.style.display=`none`"></template><template x-if="!bridgePhotoUrl(fieldRow)"><div class="jd-current-photo-empty" x-text="fieldRow.notion_label || fieldRow.notion_field || `Photo`"></div></template></div>
                                                            <div class="jd-photo-card-title" x-text="fieldRow.notion_label || fieldRow.notion_field || fieldRow.label"></div>
                                                            <div class="jd-photo-card-meta" x-text="bridgePhotoUrl(fieldRow) ? `Notion source: ${fieldRow.notion_field || fieldRow.notion_label}` : `No URL currently stored in this editable Notion photo field.`"></div>
                                                            <a class="jd-link-lite" x-show="bridgePhotoUrl(fieldRow)" :href="bridgePhotoUrl(fieldRow)" target="_blank" rel="noopener noreferrer" x-text="bridgePhotoUrl(fieldRow)"></a>
                                                            <div class="jd-photo-actions">
                                                                <button type="button" class="jd-mini jd-mini-ok" :disabled="photoMutationBusy({!! $linkExpr !!}) || !bridgePhotoUrl(fieldRow)" :title="!bridgePhotoUrl(fieldRow) ? `Disabled because this Notion photo column has no URL to import.` : (photoMutationBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : `Import this Notion photo as the WordPress profile photo`)" @click.stop="importPhoto({!! $linkExpr !!}, bridgePhotoCandidate(fieldRow))"><span x-show="{!! $linkExpr !!}.photo_busy===fieldRow.key" class="jd-spin"></span><span>Use as WP Profile Photo</span></button>
                                                                <button type="button" class="jd-mini jd-mini-ghost" :disabled="photoMutationBusy({!! $linkExpr !!}) || !bridgePhotoUrl(fieldRow)" :title="!bridgePhotoUrl(fieldRow) ? `Disabled because this row has no photo URL to save.` : (photoMutationBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : `Write this URL into ${fieldRow.notion_field || fieldRow.notion_label}`)" @click.stop="savePhotoToNotion({!! $linkExpr !!}, bridgePhotoCandidate(fieldRow), fieldRow.notion_field || fieldRow.notion_label)"><span x-show="{!! $linkExpr !!}.photo_busy===(`notion:` + fieldRow.key)" class="jd-spin jd-spin-dark"></span><span>Save URL to Notion</span></button>
                                                                <label class="jd-mini jd-mini-indigo" :title="photoMutationBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : `Upload a local image to WordPress and save the uploaded URL to this Notion column`" :style="photoMutationBusy({!! $linkExpr !!}) ? `opacity:.55;cursor:default` : ``" @click.stop><span x-show="{!! $linkExpr !!}.photo_busy===`upload`" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.photo_busy===`upload` ? `Uploading...` : `Upload to this column`"></span><input type="file" accept="image/*" style="display:none" :disabled="photoMutationBusy({!! $linkExpr !!})" @change="uploadPhotoFile({!! $linkExpr !!}, $event, fieldRow.notion_field || fieldRow.notion_label)"></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-sfpf-field-actions">
                                                        <div class="jd-field-save-status" x-show="fieldRow.save_status" x-cloak :class="fieldRow.save_status">
                                                            <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                            <span x-show="fieldRow.save_status === `error`" class="jd-field-save-x">&#10005;</span>
                                                            <span x-show="fieldRow.save_status && fieldRow.save_status !== `saving` && fieldRow.save_status !== `error`" class="jd-field-save-ok">&#10003;</span>
                                                            <span x-text="fieldRow.save_message || (fieldRow.save_status === `saving` ? `Saving...` : (fieldRow.save_status === `error` ? `Failed` : `Saved`))"></span>
                                                        </div>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ghost" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `refresh_live:both`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || fieldRowBusy({!! $linkExpr !!}, fieldRow)" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (fieldRowBusyReason({!! $linkExpr !!}, fieldRow) || `Re-pull this field from live Notion and WordPress`)" @click.stop="refreshBridgeField({!! $linkExpr !!}, fieldRow)" data-sfpf-action="refresh-live-field">Reset from live</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_notion_to_wp:wordpress`)" :disabled="!!fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldValuesEquivalent(fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || (fieldValuesEquivalent(fieldRow) ? `Already identical — nothing to copy.` : `Copy the Notion value into WordPress`)" @click.stop="copySingleFieldFromNotion({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-np-to-wp">Copy Notion to WordPress</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-notion" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_wp_to_notion:notion`)" :disabled="!!copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow) || fieldValuesEquivalent(fieldRow)" :title="copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow) || (fieldValuesEquivalent(fieldRow) ? `Already identical — nothing to copy.` : `Copy the WordPress value into Notion`)" @click.stop="copySingleFieldFromWordPress({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-wp-to-notion">Copy WordPress to Notion</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ai" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `ai_propose:field`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI proposals are inactive because no AI model is configured.` : `Ask AI to propose a value for this field`)" @click.stop="proposeAi({!! $linkExpr !!}, fieldRow.key)" data-sfpf-action="ai-propose-field"><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_propose:field`) === `saving`" class="jd-spin jd-spin-dark"></span><span x-text="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_propose:field`) === `saving` ? `Proposing...` : `Propose with AI`"></span></button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ai" x-show="fieldSupportsWebAi(fieldRow)" x-cloak :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI web search is inactive because no AI model is configured.` : `Search the web with AI and propose a researched value for this field`)" @click.stop="proposeWebAi({!! $linkExpr !!}, fieldRow.key)" data-sfpf-action="ai-web-propose-field"><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`) === `saving`" class="jd-spin jd-spin-dark"></span><span x-text="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`) === `saving` ? `Searching...` : `Search web with AI`"></span></button>
                                                    </div>
                                                    <div class="jd-control-reason" x-show="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)" x-text="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="jd-sfpf-actions" data-testid="journalist-sfpf-field-actions">
                                        <button type="button" class="jd-sfpf-btn jd-sfpf-btn-copy" :class="workspaceButtonClass({!! $linkExpr !!}, `transfer`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !bridgeWritableRows({!! $linkExpr !!}).length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!bridgeWritableRows({!! $linkExpr !!}).length ? `No writable Notion to WordPress fields are available.` : `Copy all writable Notion values to WordPress`)" @click.stop="transferCardFromNotion({!! $linkExpr !!})" data-sfpf-action="journalist-copy-notion-to-wordpress">
                                            <span class="jd-spin" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `saving`"></span>
                                            <span>Copy Notion to WordPress</span>
                                            <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `success`">✓</span>
                                            <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `error`">×</span>
                                        </button>
                                        <div class="jd-sfpf-ai-control">
                                            <button type="button" class="jd-sfpf-btn jd-sfpf-btn-ai" :class="workspaceButtonClass({!! $linkExpr !!}, `ai`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI proposals are inactive because no AI model is configured.` : `Generate field proposals from the loaded bridge values`)" @click.stop="proposeAi({!! $linkExpr !!})" data-sfpf-action="journalist-propose-with-ai">
                                                <span class="jd-spin" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `saving`"></span>
                                                <span>Propose with AI</span>
                                                <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `success`">✓</span>
                                                <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `error`">×</span>
                                            </button>
                                            <span class="jd-sfpf-agent-label">AI model for this card</span>
                                            <select class="jd-sfpf-agent-select" x-model="{!! $linkExpr !!}.ai_agent" @focus.stop="ensureAiState({!! $linkExpr !!})" @change.stop="ensureAiState({!! $linkExpr !!})" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" data-sfpf-action="card-ai-agent-select">
                                                <option value="">Default AI agent</option>
                                                <template x-for="agent in aiOptions" :key="agent.id">
                                                    <option :value="agent.id" x-text="agent.label"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <button type="button" class="jd-sfpf-btn jd-sfpf-btn-edit" :class="{!! $linkExpr !!}.manual_open ? `is-active` : ``" @click.stop="{!! $linkExpr !!}.manual_open = !{!! $linkExpr !!}.manual_open" data-sfpf-action="journalist-ai-notes-toggle">
                                            <span x-text="{!! $linkExpr !!}.manual_open ? `Hide AI notes` : `AI notes`"></span>
                                        </button>
                                        <button type="button" x-show="bridgeCardBusy({!! $linkExpr !!})" class="jd-sfpf-btn jd-sfpf-btn-cancel" @click.stop="cancelBridgeWork({!! $linkExpr !!})" data-sfpf-action="journalist-cancel-field-work">Cancel</button>
                                    </div>
                                    <div class="jd-sfpf-message" x-show="{!! $linkExpr !!}.workspace_message" :class="{!! $linkExpr !!}.workspace_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.workspace_message"></div>
                                    <div class="jd-sfpf-notes" x-show="{!! $linkExpr !!}.manual_open">
                                        <textarea class="jd-ai-notes" x-model="{!! $linkExpr !!}.ai_instructions" placeholder="Optional AI notes or unstructured instructions for this card..."></textarea>
                                    </div>

                                </div>
                            </template>
                        </div>
