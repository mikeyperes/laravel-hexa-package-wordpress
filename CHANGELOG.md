# Changelog

## 2.11.5 - 2026-11-01

- WORDPRESS-EVAL-003: recognize execution receipts after transport stdout trimming and preserve the caller payload.

## 2.11.4 - 2026-11-01

- WORDPRESS-EVAL-002: begin/end execution receipts reject false clean exits and incomplete evaluation. One explicit shared pre-execution recovery route handles completed empty native bootstraps; started/uncertain payloads are never replayed.

## 2.11.3 - 2026-10-30

- Post lists read through WP Toolkit now include each post's featured image ID, as the REST lists already did, so an author audit can flag posts without one.

## 2.11.2 - 2026-10-28

- Author photos: on Simple Local Avatars sites, older avatar keys from a previous avatar plugin now point at the new photo, so the old portrait no longer shows (WORDPRESS-AVATAR-002).
