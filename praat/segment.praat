# segment.praat — speech/silence boundaries of record.
#
# ADR-038: "Speech/silence boundaries of record are computed here (server-side
# VAD) for every recording, language and device tier, on the original timeline."
# Every recording: this runs whether or not a live transcript provider was
# available in the browser, and a provider is never the boundary mechanism.
#
# Word-level alignment is not computed here and never will be — that is ESU's
# record, from Yahura. These are speech/silence regions only.
#
# ADR-039: the output marks regions. It is never a cut list. Both speech and
# silence intervals are emitted, so a reader cannot mistake the marked-up parts
# for "the parts worth keeping".
#
# Output: one `interval=<label>|<startSeconds>|<endSeconds>` line per interval,
# preceded by the parameters used.

form Segment
  sentence inputPath
  real minimumPitch 100.0
  real timeStep 0.0
  real silenceThresholdDb -25.0
  real minSilentInterval 0.1
  real minSoundingInterval 0.1
endform

Read from file: inputPath$
sound = selected("Sound")
totalDuration = Get total duration

appendInfoLine: "praatVersion=", praatVersion$
appendInfoLine: "totalDuration=", totalDuration
appendInfoLine: "paramMinimumPitch=", minimumPitch
appendInfoLine: "paramTimeStep=", timeStep
appendInfoLine: "paramSilenceThresholdDb=", silenceThresholdDb
appendInfoLine: "paramMinSilentInterval=", minSilentInterval
appendInfoLine: "paramMinSoundingInterval=", minSoundingInterval

selectObject: sound
textgrid = To TextGrid (silences): minimumPitch, timeStep, silenceThresholdDb, minSilentInterval, minSoundingInterval, "silence", "speech"

intervalCount = Get number of intervals: 1
appendInfoLine: "intervalCount=", intervalCount

for i from 1 to intervalCount
  selectObject: textgrid
  label$ = Get label of interval: 1, i
  start = Get start time of interval: 1, i
  end = Get end time of interval: 1, i
  # An unlabelled interval is one the detector produced but did not classify.
  # It is emitted as silence rather than dropped: an unmarked stretch would
  # read as a hole in the recording, and there are no holes.
  if label$ = ""
    label$ = "silence"
  endif
  appendInfoLine: "interval=", label$, "|", start, "|", end
endfor

removeObject: sound, textgrid
