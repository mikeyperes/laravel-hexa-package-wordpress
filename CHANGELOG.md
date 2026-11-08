# 2.12.2 - 2026-11-08

- Synchronize active WP User Avatars and Simple Local Avatars for a selected author. Verify the rendered avatar against the assigned attachment, and preserve rendered URLs in profile reads.

# Changelog

## 2.12.1 - 2026-11-08

- WORDPRESS-AVATAR-004: Require active Simple Local Avatars, reject competing providers, never write legacy avatar keys, remove outgoing SLA derivatives on rollback and expose served photo URLs.


## 2.12.0 - 2026-11-07

- `changeAuthorSlug()` changes one author page address and keeps every earlier address redirecting (301) through Rank Math's redirect manager, clearing only those two author pages from LiteSpeed.

## 2.11.5 - 2026-11-01

- WORDPRESS-EVAL-003: recognize execution receipts after transport stdout trimming and preserve the caller payload.

## 2.11.4 - 2026-11-01

- WORDPRESS-EVAL-002: begin/end execution receipts reject false clean exits and incomplete evaluation. One explicit shared pre-execution recovery route handles completed empty native bootstraps; started/uncertain payloads are never replayed.

## 2.11.3 - 2026-10-30

- Post lists read through WP Toolkit now include each post's featured image ID, as the REST lists already did, so an author audit can flag posts without one.

## 2.11.2 - 2026-10-28

- Author photos: on Simple Local Avatars sites, older avatar keys from a previous avatar plugin now point at the new photo, so the old portrait no longer shows (WORDPRESS-AVATAR-002).
