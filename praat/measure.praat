# measure.praat — acoustic measurement over one range of one recording.
#
# Run by the Spoken Audio Node's pinned Praat worker as a separate process
# (ADR-038). Praat is GPL-2+ per the license read from its distribution, so it
# is invoked, never linked: the ruling that keeps it in its own process is what
# keeps this service's own licence intact.
#
# Contract with the caller:
#   - Input is a decoded, timeline-preserving rendition. Offsets in and out are
#     therefore offsets in the original (ADR-039: one timeline).
#   - Output is a key=value block on stdout, one pair per line. A value Praat
#     could not compute is printed by Praat as --undefined-- and the worker maps
#     that to null. It never guesses a number in its place.
#   - Every parameter this script was given is echoed back, because a
#     measurement without its settings is not reproducible (ADR-038).
#
# Measurements describe the signal. Nothing here decides whether a word,
# pronunciation or grammar form is correct.

form Measure
  sentence inputPath
  real fromSeconds 0.0
  real toSeconds 0.0
  real timeStep 0.0
  real pitchFloor 75.0
  real pitchCeiling 600.0
  real maxFormants 5.0
  real formantCeiling 5500.0
  real harmonicityTimeStep 0.01
endform

Read from file: inputPath$
sound = selected("Sound")

totalDuration = Get total duration
samplingFrequency = Get sampling frequency
channels = Get number of channels

# A zero `toSeconds` means "to the end", so the caller does not have to know the
# duration before asking for the whole recording.
analysisTo = toSeconds
if analysisTo <= 0
  analysisTo = totalDuration
endif
analysisFrom = fromSeconds
if analysisFrom < 0
  analysisFrom = 0
endif

appendInfoLine: "praatVersion=", praatVersion$
appendInfoLine: "totalDuration=", totalDuration
appendInfoLine: "samplingFrequency=", samplingFrequency
appendInfoLine: "channels=", channels
appendInfoLine: "analysisFrom=", analysisFrom
appendInfoLine: "analysisTo=", analysisTo
appendInfoLine: "paramTimeStep=", timeStep
appendInfoLine: "paramPitchFloor=", pitchFloor
appendInfoLine: "paramPitchCeiling=", pitchCeiling
appendInfoLine: "paramMaxFormants=", maxFormants
appendInfoLine: "paramFormantCeiling=", formantCeiling
appendInfoLine: "paramHarmonicityTimeStep=", harmonicityTimeStep

# ---- level and offset, over the whole signal ----
selectObject: sound
rootMeanSquare = Get root-mean-square: analysisFrom, analysisTo
meanAmplitude = Get mean: 0, analysisFrom, analysisTo
absolutePeak = Get absolute extremum: analysisFrom, analysisTo, "None"
appendInfoLine: "rootMeanSquare=", rootMeanSquare
appendInfoLine: "dcOffset=", meanAmplitude
appendInfoLine: "absolutePeak=", absolutePeak

# ---- pitch ----
selectObject: sound
pitch = To Pitch: timeStep, pitchFloor, pitchCeiling
meanF0 = Get mean: analysisFrom, analysisTo, "Hertz"
minF0 = Get minimum: analysisFrom, analysisTo, "Hertz", "parabolic"
maxF0 = Get maximum: analysisFrom, analysisTo, "Hertz", "parabolic"
stdevF0 = Get standard deviation: analysisFrom, analysisTo, "Hertz"
voicedFrames = Count voiced frames
totalFrames = Get number of frames
appendInfoLine: "meanF0=", meanF0
appendInfoLine: "minF0=", minF0
appendInfoLine: "maxF0=", maxF0
appendInfoLine: "stdevF0=", stdevF0
appendInfoLine: "voicedFrames=", voicedFrames
appendInfoLine: "totalFrames=", totalFrames

# ---- intensity ----
selectObject: sound
intensity = To Intensity: pitchFloor, timeStep, "yes"
meanIntensity = Get mean: analysisFrom, analysisTo, "energy"
minIntensity = Get minimum: analysisFrom, analysisTo, "parabolic"
maxIntensity = Get maximum: analysisFrom, analysisTo, "parabolic"
appendInfoLine: "meanIntensity=", meanIntensity
appendInfoLine: "minIntensity=", minIntensity
appendInfoLine: "maxIntensity=", maxIntensity

# ---- harmonics-to-noise ----
selectObject: sound
# Harmonicity has no "auto" time step: Praat refuses zero here, unlike Pitch,
# Intensity and Formant where zero means "derive it from the pitch floor". The
# caller passes one explicitly and it is echoed above with the rest.
harmonicity = To Harmonicity (cc): harmonicityTimeStep, pitchFloor, 0.1, 1.0
meanHarmonicity = Get mean: analysisFrom, analysisTo
appendInfoLine: "meanHarmonicity=", meanHarmonicity

# ---- formants ----
selectObject: sound
formant = To Formant (burg): timeStep, maxFormants, formantCeiling, 0.025, 50
f1 = Get mean: 1, analysisFrom, analysisTo, "hertz"
f2 = Get mean: 2, analysisFrom, analysisTo, "hertz"
f3 = Get mean: 3, analysisFrom, analysisTo, "hertz"
appendInfoLine: "meanF1=", f1
appendInfoLine: "meanF2=", f2
appendInfoLine: "meanF3=", f3

# ---- perturbation ----
# Reported, never certified here. The published reliability floors for these
# classes are SNR-dependent and the floor for this platform's material has not
# been ruled on, so the worker marks them unreliable on the way out.
selectObject: sound
pointProcess = To PointProcess (periodic, cc): pitchFloor, pitchCeiling
jitterLocal = Get jitter (local): analysisFrom, analysisTo, 0.0001, 0.02, 1.3
appendInfoLine: "jitterLocal=", jitterLocal

selectObject: sound
plusObject: pointProcess
shimmerLocal = Get shimmer (local): analysisFrom, analysisTo, 0.0001, 0.02, 1.3, 1.6
appendInfoLine: "shimmerLocal=", shimmerLocal

removeObject: sound, pitch, intensity, harmonicity, formant, pointProcess
