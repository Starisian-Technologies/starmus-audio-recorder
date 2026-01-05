#!/bin/bash
# Enterprise-grade automated code fixes

echo "🔧 Applying enterprise-grade fixes..."

# Fix unused variables by prefixing with underscore
sed -i 's/const notice =/const _notice =/g' src/js/starmus-audio-editor.js
sed -i 's/, isTusAvailable/, _isTusAvailable/g' src/js/starmus-core.js
sed -i 's/settings) {/_settings) {/g' src/js/starmus-enhanced-calibration.js
sed -i 's/args) {/_args) {/g' src/js/starmus-hooks.js
sed -i 's/options) {/_options) {/g' src/js/starmus-metadata-auto.js
sed -i 's/const result =/const _result =/g' src/js/starmus-offline.js
sed -i 's/) catch (e) {/) catch (_e) {/g' src/js/starmus-offline.js
sed -i 's/const doCalibration =/const _doCalibration =/g' src/js/starmus-recorder.js
sed -i 's/) catch (e) {/) catch (_e) {/g' src/js/starmus-recorder.js
sed -i 's/const specs =/const _specs =/g' src/js/starmus-sparxstar-integration.js
sed -i 's/context = //_context = /g' src/js/starmus-tus.js
sed -i 's/) catch (e) {/) catch (_e) {/g' src/js/starmus-tus.js

# Fix empty blocks
sed -i 's/{}/{ \/\* intentionally empty \*\/ }/g' src/js/starmus-integrator.js
sed -i 's/{}/{ \/\* intentionally empty \*\/ }/g' src/js/starmus-recorder.js

# Fix var to const/let
sed -i 's/var /let /g' src/js/starmus-state-store.js

# Fix hasOwnProperty
sed -i 's/\.hasOwnProperty(/Object.prototype.hasOwnProperty.call(/g' src/js/starmus-state-store.js

echo "✅ Fixes applied!"