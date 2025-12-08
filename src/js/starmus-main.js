// 1. MUST RUN FIRST: Defines global StarmusHooks + Store
import './starmus-hooks.js';
import './starmus-state-store.js';

// 2. Upload + Queue systems (used by Core)
import './starmus-tus.js';
import './starmus-offline.js';

// 3. Recorder + UI subsystems
import './starmus-ui.js';
import './starmus-core.js';
import './starmus-recorder.js';
import './starmus-transcript-controller.js';

// 4. LAST: The orchestrator (starmus-integrator.js)
import './starmus-integrator.js';
