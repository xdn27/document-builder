#!/usr/bin/env bash
#
# Pembungkus untuk repo akademik-maqiis, yang PHP-nya hidup di container maqiis10.
# Isinya pindah ke bin/document-builder-verify-print (portabel, spec §14.2);
# perintah lama di CLAUDE.md tetap bekerja:
#   bash packages/document-builder/tools/verify-print.sh <fixture.json> [halaman]

export DOCUMENT_BUILDER_CONTAINER="${DOCUMENT_BUILDER_CONTAINER:-maqiis10}"
exec "$(dirname "${BASH_SOURCE[0]}")/../bin/document-builder-verify-print" "$@"
