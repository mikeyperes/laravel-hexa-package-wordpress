<?php

namespace hexa_package_wordpress\Media;

use hexa_package_media\Data\MediaArtifact;
use hexa_package_wordpress\Services\WordPressManagerService;

final class WordPressMediaGateway
{
    /** Defines $galleryIds(): attachment IDs from a raw gallery value (IDs, image arrays, JSON or serialized text). */
    private const GALLERY_IDS_PHP = '$galleryIds=function($value)use(&$galleryIds){if(is_string($value)){$decoded=json_decode($value,true);if(is_array($decoded)){return $galleryIds($decoded);}$value=maybe_unserialize($value);if(is_string($value)){preg_match_all("/\\d+/",$value,$m);$value=$m[0]??[];}}$ids=[];foreach((array)$value as $item){$id=is_array($item)?(int)($item["ID"]??$item["id"]??$item["media_id"]??0):(is_numeric($item)?(int)$item:0);if($id>0&&!in_array($id,$ids,true)){$ids[]=$id;}}return $ids;};';

    public function __construct(private readonly WordPressManagerService $wordpress)
    {
    }

    public function manager(): WordPressManagerService
    {
        return $this->wordpress;
    }

    public function upload(array $target, MediaArtifact $artifact, array $attributes = []): array
    {
        $upload = $this->wordpress->uploadMedia(
            $target,
            $artifact->path,
            $artifact->filename,
            (string) ($attributes["alt_text"] ?? ""),
            (string) ($attributes["caption"] ?? ""),
            (string) ($attributes["description"] ?? ""),
        );
        if (!($upload["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($upload["message"] ?? "WordPress media upload failed."),
                "upload" => $upload,
            ];
        }

        $mediaId = $this->mediaIdFromResponse($upload);
        if ($mediaId <= 0) {
            return [
                "success" => false,
                "message" => "WordPress accepted the media upload but returned no attachment ID.",
                "upload" => $upload,
            ];
        }

        $inspection = $this->inspect($target, $mediaId);
        if (!($inspection["success"] ?? false)) {
            return array_merge($inspection, ["upload" => $upload, "media_id" => $mediaId]);
        }

        $metadata = $this->wordpress->updateMedia($target, $mediaId, [
            "alt_text" => (string) ($attributes["alt_text"] ?? ""),
            "caption" => (string) ($attributes["caption"] ?? ""),
            "description" => (string) ($attributes["description"] ?? ""),
            "meta" => [
                "_hexa_media_sha256" => $artifact->sha256,
                "_hexa_media_source_url" => $artifact->sourceUrl,
                "_hexa_media_original_filename" => $artifact->filename,
                "_hexa_media_pipeline" => "hexa-package-media",
            ],
        ]);

        if (!($metadata["success"] ?? false)) {
            return [
                "success" => false,
                "message" => "WordPress created attachment #{$mediaId}, but media metadata verification failed: " . (string) ($metadata["message"] ?? "unknown error"),
                "media_id" => $mediaId,
                "upload" => $upload,
                "inspection" => $inspection,
                "metadata" => $metadata,
            ];
        }

        return [
            "success" => true,
            "message" => "WordPress image attachment #{$mediaId} uploaded and verified.",
            "media_id" => $mediaId,
            "wp_media_id" => $mediaId,
            "url" => (string) ($inspection["url"] ?? ""),
            "mime_type" => (string) ($inspection["mime_type"] ?? ""),
            "upload" => $upload,
            "inspection" => $inspection,
            "metadata" => $metadata,
        ];
    }

    public function findExactMatch(array $target, MediaArtifact $artifact, string $strategy, array $hints = []): array
    {
        $strategies = ["current", "sha256", "source_url", "filename", "fingerprint"];
        if (!in_array($strategy, $strategies, true)) {
            return ["success" => false, "found" => false, "media_id" => 0, "message" => "Unknown WordPress media matching strategy."];
        }
        if (!preg_match("/^[a-f0-9]{64}$/", $artifact->sha256)) {
            return ["success" => false, "found" => false, "media_id" => 0, "message" => "The selected image has no valid SHA-256 fingerprint."];
        }
        if (!$this->wordpress->usesWpToolkit($target)) {
            return $strategy === "current"
                ? $this->restCurrentMatch($target, $artifact, max(0, (int) ($hints["current_media_id"] ?? 0)))
                : ["success" => false, "found" => false, "media_id" => 0, "message" => "Exact WordPress filesystem matching is unavailable for this REST-only connection."];
        }

        $currentMediaId = max(0, (int) ($hints["current_media_id"] ?? 0));
        $parts = [
            '$strategy=' . var_export($strategy, true) . ';',
            '$targetHash=' . var_export(strtolower($artifact->sha256), true) . ';',
            '$sourceUrl=' . var_export($artifact->sourceUrl, true) . ';',
            '$filename=' . var_export($artifact->filename, true) . ';',
            '$mimeType=' . var_export($artifact->mimeType, true) . ';',
            '$targetBytes=' . $artifact->bytes . ';',
            '$targetWidth=' . $artifact->width . ';',
            '$targetHeight=' . $artifact->height . ';',
            '$currentMediaId=' . $currentMediaId . ';',
            'global $wpdb;',
            '$ids=[];$invalid=[];$scanned=0;$hashed=0;$found=0;$url="";$verification="";$matchedBytes=0;$matchedWidth=0;$matchedHeight=0;',
            'if($strategy==="current"&&$currentMediaId>0){$ids=[$currentMediaId];}',
            'if($strategy==="sha256"){$ids=get_posts(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>100,"fields"=>"ids","meta_key"=>"_hexa_media_sha256","meta_value"=>$targetHash,"orderby"=>"ID","order"=>"DESC","no_found_rows"=>true]);}',
            'if($strategy==="source_url"&&$sourceUrl!==""){$ids=array_merge($ids,get_posts(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>100,"fields"=>"ids","meta_key"=>"_hexa_media_source_url","meta_value"=>$sourceUrl,"orderby"=>"ID","order"=>"DESC","no_found_rows"=>true]));$guidIds=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND guid=%s ORDER BY ID DESC LIMIT 100","attachment",$sourceUrl));$ids=array_merge($ids,(array)$guidIds);}',
            'if($strategy==="filename"&&$filename!==""){$safe=sanitize_file_name($filename);$base=pathinfo($safe,PATHINFO_FILENAME);$slug=sanitize_title($base);$ids=array_merge($ids,get_posts(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>100,"fields"=>"ids","meta_key"=>"_hexa_media_original_filename","meta_value"=>$safe,"orderby"=>"ID","order"=>"DESC","no_found_rows"=>true]));$fileLike="%/".$wpdb->esc_like($safe);$attachedIds=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND (meta_value=%s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 100","_wp_attached_file",$safe,$fileLike));$titleIds=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND (post_name=%s OR post_title=%s) ORDER BY ID DESC LIMIT 100","attachment",$slug,$base));$ids=array_merge($ids,(array)$attachedIds,(array)$titleIds);}',
            'if($strategy==="fingerprint"){$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND post_mime_type=%s ORDER BY ID DESC","attachment","inherit",$mimeType));}',
            '$ids=array_values(array_unique(array_filter(array_map("intval",(array)$ids))));$candidateCount=count($ids);',
            'foreach($ids as $id){$scanned++;if($id<=0||!wp_attachment_is_image($id)){$invalid[]=$id;continue;}$file=get_attached_file($id);if(!$file||!is_file($file)||filesize($file)<=0){$invalid[]=$id;continue;}$candidateMime=(string)get_post_mime_type($id);$candidateBytes=(int)filesize($file);$meta=wp_get_attachment_metadata($id);$candidateWidth=(int)($meta["width"]??0);$candidateHeight=(int)($meta["height"]??0);$indexedHash=strtolower(trim((string)get_post_meta($id,"_hexa_media_sha256",true)));if(preg_match("/^[a-f0-9]{64}$/",$indexedHash)&&hash_equals($targetHash,$indexedHash)){$verification="indexed_sha256";}else{if($candidateMime!==$mimeType||$candidateBytes!==$targetBytes||($targetWidth>0&&$candidateWidth>0&&$candidateWidth!==$targetWidth)||($targetHeight>0&&$candidateHeight>0&&$candidateHeight!==$targetHeight)){continue;}$actualHash=@hash_file("sha256",$file);$hashed++;if(!is_string($actualHash)||!hash_equals($targetHash,strtolower($actualHash))){continue;}$verification="computed_sha256";}$found=$id;$url=(string)wp_get_attachment_url($id);$matchedBytes=$candidateBytes;$matchedWidth=$candidateWidth;$matchedHeight=$candidateHeight;update_post_meta($id,"_hexa_media_sha256",$targetHash);if($sourceUrl!==""){update_post_meta($id,"_hexa_media_source_url",$sourceUrl);}if($filename!==""){update_post_meta($id,"_hexa_media_original_filename",$filename);}update_post_meta($id,"_hexa_media_fingerprint_version","2");break;}',
            'echo "HEXA_MEDIA_EXACT_MATCH:".wp_json_encode(["success"=>true,"found"=>$found>0,"media_id"=>$found,"url"=>$url,"strategy"=>$strategy,"verification"=>$verification,"candidate_count"=>$candidateCount,"scanned_count"=>$scanned,"hashed_count"=>$hashed,"bytes"=>$matchedBytes,"width"=>$matchedWidth,"height"=>$matchedHeight,"invalid_media_ids"=>$invalid]);',
        ];
        $result = $this->wordpress->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return [
                "success" => false,
                "found" => false,
                "media_id" => 0,
                "message" => (string) ($result["message"] ?? "Exact WordPress media lookup failed."),
            ];
        }

        $payload = $this->decode((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_EXACT_MATCH:");

        return is_array($payload)
            ? $payload
            : ["success" => false, "found" => false, "media_id" => 0, "message" => "Exact WordPress media lookup returned an invalid response."];
    }

    public function findBySha256(array $target, string $sha256): array
    {
        if (!preg_match("/^[a-f0-9]{64}$/", $sha256)) {
            return ["success" => true, "found" => false, "media_id" => 0];
        }

        $php = '$hash=' . var_export($sha256, true) . ';'
            . '$ids=get_posts(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>25,"fields"=>"ids","meta_key"=>"_hexa_media_sha256","meta_value"=>$hash,"orderby"=>"ID","order"=>"DESC"]);'
            . '$found=0;$url="";$invalid=[];foreach($ids as $candidate){$id=(int)$candidate;$file=get_attached_file($id);$bytes=$file&&is_file($file)?(int)filesize($file):0;$candidateUrl=(string)wp_get_attachment_url($id);$valid=$id>0&&wp_attachment_is_image($id)&&$bytes>0&&filter_var($candidateUrl,FILTER_VALIDATE_URL);if($valid){$found=$id;$url=$candidateUrl;break;}$invalid[]=$id;}'
            . 'echo "HEXA_MEDIA_HASH:" . wp_json_encode(["success"=>true,"found"=>$found>0,"media_id"=>$found,"url"=>$url,"invalid_media_ids"=>$invalid]);';
        $result = $this->wordpress->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "found" => false, "media_id" => 0, "message" => (string) ($result["message"] ?? "Media hash lookup failed.")];
        }

        $payload = $this->decode((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_HASH:");

        return is_array($payload) ? $payload : ["success" => false, "found" => false, "media_id" => 0, "message" => "Media hash lookup response was invalid."];
    }

    public function inspect(array $target, int $mediaId): array
    {
        if ($mediaId <= 0) {
            return ["success" => false, "message" => "A WordPress media ID is required."];
        }

        if (!$this->wordpress->usesWpToolkit($target)) {
            return $this->restInspect($target, $mediaId);
        }

        $php = '$id=' . $mediaId . ';$post=get_post($id);$file=get_attached_file($id);$meta=wp_get_attachment_metadata($id);$url=(string)wp_get_attachment_url($id);'
            . '$attachmentOk=$post&&$post->post_type==="attachment"&&wp_attachment_is_image($id);$fileExists=$file&&is_file($file);$bytes=$fileExists?(int)filesize($file):0;$urlOk=(bool)filter_var($url,FILTER_VALIDATE_URL);$ok=$attachmentOk&&$fileExists&&$bytes>0&&$urlOk;'
            . '$message=$ok?"WordPress image attachment and file verified.":(!$attachmentOk?"WordPress media is missing or is not an image attachment.":(!$fileExists?"WordPress attachment record exists, but its media file is missing.":($bytes<=0?"WordPress attachment record exists, but its media file is empty.":"WordPress attachment URL is invalid.")));'
            . 'echo "HEXA_MEDIA_INSPECT:" . wp_json_encode(["success"=>(bool)$ok,"message"=>$message,"media_id"=>$id,"url"=>$urlOk?$url:"","mime_type"=>$attachmentOk?(string)get_post_mime_type($id):"","file_exists"=>(bool)$fileExists,"bytes"=>$bytes,"width"=>(int)($meta["width"]??0),"height"=>(int)($meta["height"]??0),"sha256"=>(string)get_post_meta($id,"_hexa_media_sha256",true)]);';
        $result = $this->wordpress->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WordPress media inspection failed."), "media_id" => $mediaId];
        }

        $payload = $this->decode((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_INSPECT:");

        return is_array($payload) ? $payload : ["success" => false, "message" => "WordPress media inspection response was invalid.", "media_id" => $mediaId];
    }

    public function featuredImageState(array $target, int $postId): array
    {
        $php = '$postId=' . $postId . ';$post=get_post($postId);$id=$post?(int)get_post_thumbnail_id($postId):0;'
            . 'echo "HEXA_FEATURED_STATE:" . wp_json_encode(["success"=>(bool)$post,"message"=>$post?"Featured image state loaded.":"WordPress post was not found.","post_id"=>$postId,"media_id"=>$id,"url"=>$id?(string)wp_get_attachment_url($id):"","permalink"=>$post?(string)get_permalink($postId):""]);';
        $result = $this->wordpress->evaluatePhp($target, $php);
        $payload = ($result["success"] ?? false) ? $this->decode((string) ($result["stdout"] ?? ""), "HEXA_FEATURED_STATE:") : null;

        return is_array($payload) ? $payload : ["success" => false, "message" => (string) ($result["message"] ?? "Featured image state could not be loaded."), "post_id" => $postId, "media_id" => 0];
    }

    public function setFeaturedImage(array $target, int $postId, int $mediaId): array
    {
        $php = '$postId=' . $postId . ';$mediaId=' . $mediaId . ';$post=get_post($postId);'
            . 'if(!$post){echo "HEXA_FEATURED_SET:" . wp_json_encode(["success"=>false,"message"=>"WordPress post was not found."]);return;}'
            . 'if($mediaId>0&&!wp_attachment_is_image($mediaId)){echo "HEXA_FEATURED_SET:" . wp_json_encode(["success"=>false,"message"=>"WordPress media is not an image attachment."]);return;}'
            . '$current=(int)get_post_thumbnail_id($postId);$alreadyAssigned=$mediaId>0?$current===$mediaId:$current===0;'
            . '$ok=$alreadyAssigned?true:($mediaId>0?(bool)set_post_thumbnail($postId,$mediaId):(bool)delete_post_thumbnail($postId));clean_post_cache($postId);$stored=(int)get_post_thumbnail_id($postId);'
            . '$verified=$mediaId>0?$stored===$mediaId:$stored===0;$success=$ok&&$verified;'
            . 'echo "HEXA_FEATURED_SET:" . wp_json_encode(["success"=>$success,"message"=>$success?($alreadyAssigned?"Featured image was already assigned and verified.":"Featured image assignment verified."):"Featured image assignment did not verify.","post_id"=>$postId,"media_id"=>$stored,"url"=>$stored?(string)wp_get_attachment_url($stored):"","permalink"=>(string)get_permalink($postId),"already_assigned"=>$alreadyAssigned]);';
        $result = $this->wordpress->evaluatePhp($target, $php);
        $payload = ($result["success"] ?? false) ? $this->decode((string) ($result["stdout"] ?? ""), "HEXA_FEATURED_SET:") : null;

        return is_array($payload) ? $payload : ["success" => false, "message" => (string) ($result["message"] ?? "Featured image assignment failed.")];
    }

    /**
     * A post's gallery field as attachment IDs, read like the field's owner does:
     * ACF (raw) when active, otherwise post meta in ACF's layout.
     */
    public function galleryState(array $target, int $postId, string $field): array
    {
        $php = '$postId=' . $postId . ';$field=' . var_export($field, true) . ';$post=get_post($postId);'
            . self::GALLERY_IDS_PHP
            . 'if(!$post){echo "HEXA_GALLERY_STATE:" . wp_json_encode(["success"=>false,"message"=>"WordPress post was not found.","post_id"=>$postId,"media_ids"=>[]]);return;}'
            . '$ids=$galleryIds(function_exists("get_field")?get_field($field,$postId,false):get_post_meta($postId,$field,true));$urls=[];foreach($ids as $id){$urls[(string)$id]=(string)wp_get_attachment_url($id);}'
            . 'echo "HEXA_GALLERY_STATE:" . wp_json_encode(["success"=>true,"message"=>"Gallery field loaded.","post_id"=>$postId,"field"=>$field,"media_ids"=>$ids,"urls"=>$urls]);';

        return $this->galleryCall($target, $php, "HEXA_GALLERY_STATE:", "Gallery field could not be loaded.");
    }

    /** Store exactly these attachment IDs in a post's gallery field and read them back. */
    public function setGallery(array $target, int $postId, string $field, array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map("intval", $mediaIds), static fn (int $id): bool => $id > 0)));
        $php = '$postId=' . $postId . ';$field=' . var_export($field, true) . ';$ids=' . var_export($ids, true) . ';$post=get_post($postId);'
            . self::GALLERY_IDS_PHP
            . 'if(!$post){echo "HEXA_GALLERY_SET:" . wp_json_encode(["success"=>false,"message"=>"WordPress post was not found.","post_id"=>$postId,"media_ids"=>[]]);return;}'
            . '$read=function()use($field,$postId,$galleryIds){return $galleryIds(function_exists("get_field")?get_field($field,$postId,false):get_post_meta($postId,$field,true));};'
            . 'if($read()!==$ids){$saved=function_exists("update_field")?(bool)update_field($field,$ids,$postId):false;if(!$saved||$read()!==$ids){update_post_meta($postId,$field,$ids);}}'
            . 'clean_post_cache($postId);$stored=$read();$success=$stored===$ids;'
            . 'echo "HEXA_GALLERY_SET:" . wp_json_encode(["success"=>$success,"message"=>$success?"Gallery field saved and verified.":"Gallery field did not verify.","post_id"=>$postId,"field"=>$field,"media_ids"=>$stored]);';

        return $this->galleryCall($target, $php, "HEXA_GALLERY_SET:", "Gallery field could not be saved.");
    }

    public function purgePostCache(array $target, int $postId): array
    {
        if ($postId <= 0) {
            return ["success" => false, "message" => "A WordPress post ID is required for cache invalidation.", "actions" => []];
        }

        $parts = [
            '$postId=' . $postId . ';',
            '$post=get_post($postId);$actions=[];$warnings=[];',
            'if(!$post){echo "HEXA_POST_CACHE_PURGE:".wp_json_encode(["success"=>false,"message"=>"WordPress post was not found.","post_id"=>$postId,"actions"=>[]]);return;}',
            '$permalink=(string)get_permalink($postId);',
            'if(function_exists("clean_post_cache")){clean_post_cache($postId);$actions[]="clean_post_cache";}',
            '$active=(array)get_option("active_plugins",[]);$litespeed=in_array("litespeed-cache/litespeed-cache.php",$active,true)||defined("LSCWP_V");',
            'if($litespeed&&has_action("litespeed_purge_post")){ob_start();do_action("litespeed_purge_post",$postId);ob_end_clean();$actions[]="litespeed_purge_post";}',
            'elseif($litespeed&&class_exists("LiteSpeed_Cache_API")&&method_exists("LiteSpeed_Cache_API","purge_post")){LiteSpeed_Cache_API::purge_post($postId);$actions[]="litespeed_api_purge_post";}',
            'elseif($litespeed&&$permalink!==""&&has_action("litespeed_purge_url")){ob_start();do_action("litespeed_purge_url",$permalink);ob_end_clean();$actions[]="litespeed_purge_url";}',
            'elseif($litespeed){$warnings[]="LiteSpeed was detected, but no targeted purge hook was available in this WordPress context.";}',
            '$actions=array_values(array_unique($actions));',
            'echo "HEXA_POST_CACHE_PURGE:".wp_json_encode(["success"=>true,"message"=>count($actions)." post cache action(s) requested.","post_id"=>$postId,"permalink"=>$permalink,"actions"=>$actions,"warnings"=>array_values(array_unique($warnings)),"litespeed_detected"=>$litespeed]);',
        ];
        $result = $this->wordpress->evaluatePhp($target, implode("", $parts));
        $payload = ($result["success"] ?? false)
            ? $this->decode((string) ($result["stdout"] ?? ""), "HEXA_POST_CACHE_PURGE:")
            : null;

        if (!is_array($payload)) {
            $fallback = $this->wordpress->purgeSiteCache($target);
            if ($fallback["success"] ?? false) {
                return [
                    "success" => true,
                    "message" => "Targeted post cache invalidation was unavailable; the WordPress site cache was purged instead.",
                    "post_id" => $postId,
                    "permalink" => "",
                    "actions" => array_values(array_unique((array) ($fallback["actions"] ?? []))),
                    "warnings" => array_values(array_filter([(string) ($result["message"] ?? "Targeted post cache response was invalid.")])),
                    "litespeed_detected" => (bool) ($fallback["litespeed_detected"] ?? false),
                    "fallback" => "site_cache",
                ];
            }

            return [
                "success" => false,
                "message" => (string) ($result["message"] ?? $fallback["message"] ?? "WordPress post cache invalidation failed."),
                "post_id" => $postId,
                "actions" => [],
            ];
        }

        if (!($payload["success"] ?? false)) {
            return $payload;
        }

        $actions = array_values(array_unique((array) ($payload["actions"] ?? [])));
        $warnings = array_values(array_filter((array) ($payload["warnings"] ?? [])));
        $litespeedDetected = (bool) ($payload["litespeed_detected"] ?? false);
        $litespeedPurged = count(array_filter($actions, fn ($action) => str_starts_with((string) $action, "litespeed_"))) > 0;
        $fallback = null;

        if ($litespeedDetected && !$litespeedPurged) {
            $fallback = $this->wordpress->purgeSiteCache($target);
            foreach ((array) ($fallback["actions"] ?? []) as $action) {
                $actions[] = (string) $action;
            }
            foreach ((array) ($fallback["warnings"] ?? []) as $warning) {
                $warnings[] = (string) $warning;
            }
            $litespeedPurged = count(array_filter($actions, fn ($action) => str_starts_with((string) $action, "litespeed_"))) > 0;
        }

        $actions = array_values(array_unique($actions));
        $warnings = array_values(array_unique(array_filter($warnings)));
        $success = in_array("clean_post_cache", $actions, true) && (!$litespeedDetected || $litespeedPurged);

        return array_replace($payload, [
            "success" => $success,
            "message" => $success
                ? "WordPress post cache invalidated through " . implode(", ", $actions) . "."
                : "WordPress saved the destination, but its public page cache could not be fully invalidated.",
            "actions" => $actions,
            "warnings" => $warnings,
            "fallback" => is_array($fallback) ? "site_cache" : "",
        ]);
    }

    public function purgeSiteCache(array $target): array
    {
        return $this->wordpress->purgeSiteCache($target);
    }

    public function delete(array $target, int $mediaId): array
    {
        return $this->wordpress->deleteMedia($target, $mediaId, true);
    }

    /**
     * REST and HWS Base Tools connections cannot read the uploads folder, so an
     * attachment is verified from its REST record: an image with a valid URL
     * and a non-empty file (size or dimensions reported by WordPress).
     */
    private function restInspect(array $target, int $mediaId): array
    {
        $media = $this->wordpress->getMedia($target, $mediaId);
        $data = (array) ($media["data"] ?? []);
        $details = (array) ($data["media_details"] ?? []);
        $url = (string) ($data["source_url"] ?? "");
        $isImage = ($data["media_type"] ?? "") === "image" && str_starts_with((string) ($data["mime_type"] ?? ""), "image/");
        $bytes = (int) ($details["filesize"] ?? 0);
        $width = (int) ($details["width"] ?? 0);
        $height = (int) ($details["height"] ?? 0);
        $ok = ($media["success"] ?? false) && $isImage && filter_var($url, FILTER_VALIDATE_URL) && ($bytes > 0 || ($width > 0 && $height > 0));

        return [
            "success" => (bool) $ok,
            "message" => $ok ? "WordPress image attachment verified over REST." : (!($media["success"] ?? false) ? (string) ($media["message"] ?? "WordPress media lookup failed.") : "WordPress media is missing, is not an image, or has no file."),
            "media_id" => $mediaId,
            "url" => $ok ? $url : "",
            "mime_type" => $isImage ? (string) $data["mime_type"] : "",
            "file_exists" => (bool) $ok,
            "bytes" => $bytes,
            "width" => $width,
            "height" => $height,
            "sha256" => "",
        ];
    }

    /** Reuse the current attachment over REST when its type, size and dimensions equal the new image. */
    private function restCurrentMatch(array $target, MediaArtifact $artifact, int $currentMediaId): array
    {
        if ($currentMediaId <= 0) {
            return ["success" => true, "found" => false, "media_id" => 0, "strategy" => "current", "candidate_count" => 0, "scanned_count" => 0, "hashed_count" => 0];
        }
        $current = $this->restInspect($target, $currentMediaId);
        $found = ($current["success"] ?? false)
            && $current["mime_type"] === $artifact->mimeType
            && $current["bytes"] > 0 && $current["bytes"] === $artifact->bytes
            && ($artifact->width <= 0 || $current["width"] === $artifact->width)
            && ($artifact->height <= 0 || $current["height"] === $artifact->height);

        return [
            "success" => true,
            "found" => $found,
            "media_id" => $found ? $currentMediaId : 0,
            "url" => $found ? $current["url"] : "",
            "strategy" => "current",
            "verification" => $found ? "rest_size_and_dimensions" : "",
            "candidate_count" => 1,
            "scanned_count" => 1,
            "hashed_count" => 0,
        ];
    }

    private function galleryCall(array $target, string $php, string $marker, string $failure): array
    {
        $result = $this->wordpress->evaluatePhp($target, $php);
        $payload = ($result["success"] ?? false) ? $this->decode((string) ($result["stdout"] ?? ""), $marker) : null;

        return is_array($payload) ? $payload : ["success" => false, "message" => (string) ($result["message"] ?? $failure), "media_ids" => []];
    }

    private function mediaIdFromResponse(array $response): int
    {
        foreach ([
            $response["data"]["media_id"] ?? null,
            $response["data"]["id"] ?? null,
            $response["media_id"] ?? null,
            $response["wp_media_id"] ?? null,
            $response["id"] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    private function decode(string $stdout, string $marker): ?array
    {
        $position = strrpos($stdout, $marker);
        if ($position === false) {
            return null;
        }

        $payload = trim(substr($stdout, $position + strlen($marker)));
        $line = strtok($payload, "\r\n");
        $decoded = json_decode((string) $line, true);

        return is_array($decoded) ? $decoded : null;
    }
}
