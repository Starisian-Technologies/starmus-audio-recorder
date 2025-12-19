#!/bin/bash

echo "--- Setting up test pages in the TESTING environment ---"

# Page 1: The Audio Recorder
wp-env run tests-cli -- wp post create \
  --post_type=page \
  --post_title='Test Page With Recorder' \
  --post_content='<!-- wp:shortcode -->[starmus_audio_recorder_form]<!-- /wp:shortcode -->' \
  --post_status=publish \
  --post_name='test-page-with-recorder'

# Page 2: My Recordings
wp-env run tests-cli -- wp post create \
  --post_type=page \
  --post_title='Test Page For My Recordings' \
  --post_content='<!-- wp:shortcode -->[starmus_my_recordings]<!-- /wp:shortcode -->' \
  --post_status=publish \
  --post_name='test-page-my-recordings'

# STEP 1: Set the permalink structure to enable "pretty" URLs.
echo "--- Setting permalink structure ---"
wp-env run tests-cli -- wp rewrite structure '/%postname%/' --hard

# STEP 2: Flush the rewrite rules to make the new pages accessible.
echo "--- Flushing rewrite rules ---"
wp-env run tests-cli -- wp rewrite flush --hard

echo "--- Test environment is ready ---"
