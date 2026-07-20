<?php

namespace hexa_package_wordpress\Media;

use hexa_package_media\Data\MediaArtifact;
use hexa_package_wordpress\Services\WordPressManagerService;

final class WordPressMediaGateway
{
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

    public function delete(array $target, int $mediaId): array
    {
        return $this->wordpress->deleteMedia($target, $mediaId, true);
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
