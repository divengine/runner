# SFM Compatibility

Runner is an independent PHP implementation of the
[Sequential Flow Model](https://github.com/rafageist/book-sequential-flow-model).
Its runtime reference is the Python execution runtime `vvm_strider_jobs`
(`lib/core.py`, `lib/models/job_lib.py`). The development-time modeler
`vvm_striders` (canonical YAML, compiler, validator, simulator) is the secondary
reference for YAML shape and semantics (`docs/FLOW_SIMULATION.md`,
ADR-S03, ADR-I2-S01, ADR-I2-S02).

Status of v0.1.0. Test names refer to `tests/RunnerTest.php`.

## Terminology

| SFM / Python | Runner |
|---|---|
| Strider | Block (`blocks:` in YAML, `_<id>` callable in context) |
| `_strider_<id>` | `_<block_id>` |
| `_current_strider_id` | `_current_block_id` |
| `_flow_state.strider` | `_flow_state.block` |
| `JumpException` / `PauseException` | exception messages carrying `_exception_jump` / `_exception_pause` tokens |

## Supported

| Behavior | Notes | Tests |
|---|---|---|
| Shared mutable context | One array passed by reference to flows, activities and conditions. | `testRunUpdatesContext`, `testWrappersAndUpdateContextMutateSharedContext` |
| Ordered blocks and steps | First block is the entry; blocks do not fall through. | `testRunBusinessFixtureFlowUsesActivitiesAndBlocks`, `testRunYamlSupportsListBlocksAndStepsSyntax` |
| Step order | condition → context → call → activity → jump → pause. | `testCallReturnsIntoCallingStepBeforeItsActivity`, `testFalseConditionSkipsEveryActionOfTheStep` |
| Condition gates the whole step, including `call` | Same as ADR-S03 (compiler 1.0.0). | `testFalseConditionSkipsEveryActionOfTheStep` |
| Activity result stored under the step id | `$context[<step_id>] = result`. | `testRunBusinessFixtureFlowUsesActivitiesAndBlocks` |
| `call` with return | Runs the target block from the target step, then the calling step continues with its activity, jump and pause. | `testCallReturnsIntoCallingStepBeforeItsActivity` |
| `jump` without return | Nearest invocation running the target block restarts at the target step; otherwise the target becomes the running block and call continuations are dropped. | `testJumpOutOfCalledBlockDropsCallContinuation`, `testJumpToCalledBlockRestartsNearestInvocationAndReturns`, `testJumpToCallerBlockRestartsCallerAtTarget`, `testFlowLoopHandlesJumpTokenAndContinuesWithSavedBlock` |
| Target resolution | `block.step`; `block.` = step 0; `.step` = current block; unknown targets fail at compile time. | `testShortTargetsUseCurrentBlockAndFirstStep`, `testInvalidTargetReferenceIsRejectedAtCompileTime` |
| Pause and resume (`_flow_state`) | Resume continues after the paused step. | `testRunPauseResumeFixtureFlowResumesFromSavedStep`, `testCheckPauseUsesSavedFlowState` |
| Pause requested by an activity | Pauses at that step; the result is not stored. | `testActivityPauseCheckpointsItsStepWithoutStoringResult` |
| `_autoresume` | Resumes immediately once. | `testRunAutoResumesWhenAutoresumeIsEnabled`, `testAutoresumeInsideCalledBlockReturnsIntoCaller` |
| Flow used as an activity | Own control scope; parent blocks (`_flow_blocks`), position and call stack restored; `_flow_state`/`_autoresume` and any other key kept as the activity left them. | `testNestedFlowActivityRestoresParentBlocks`, `testActivityClosuresUnderUnderscoreKeysPersist` |
| Pause inside a nested flow | Early return of the nested flow (result `null`); the parent continues (ADR-I2-S01). | `testPauseInsideNestedFlowIsAnEarlyReturn` |
| Activity/condition resolution | PHP file under `_root_folder` / `DIV_RUNNER_ROOT_FOLDER`, then path, then callable name. | `testImporterPrefersFileOverSameNamedCallable`, `testRunYamlResolvesActivitiesUsingRuntimeRootFolder`, `testImporterAcceptsNamespacedCallableIdentifier` |
| Missing or failing functions | Run ends in `error` with a log entry naming the function. | `testMissingActivityMarksErrorState`, `testImporterHandlesRequireErrorAndLogsException`, `testImporterFailsWhenFileDoesNotReturnCallable` |
| Deterministic compilation | Same YAML ⇒ byte-identical PHP. | `testGeneratedCodeIsDeterministic` |
| Schema validation | Unknown flow/block/step keys and wrong types are rejected. | `testSchemaRejectsUnknownKeysAndWrongTypes` |
| YAML subset used by real flows | Block scalars, one-line flow collections, nested and indentless sequences, comments, quoted keys, YAML 1.1 scalar resolution as PyYAML `safe_load` (`yes`/`off` booleans, `0x1F`, `017`, `.5`). All 22 `vvm_striders` fixture flows parse identically to PyYAML. | `testYamlParserSupportsBlockScalarsFlowCollectionsAndComments`, `testYamlParserMatchesPyYamlOnSequenceItemsAndScalars`, `testYamlParserRejectsMisindentedBlockScalarAndBadQuotes` |
| Errors do not leave resumable state | A failed run drops pending call frames, so running again starts cleanly. | `testErrorInsideCalledBlockLeavesNoStaleCallFrames` |

## Known divergences

| Behavior | `vvm_strider_jobs` | Runner | Reason |
|---|---|---|---|
| Pause inside a called block | Call return frames are Python frames and are not persisted: after resume the called block finishes and the flow ends (ADR-I2-S02). | Return frames are plain data in `_flow_stack`, persisted with the context: after resume the called block finishes and control returns into the calling step. | Keeps `call` semantics across a pause. Tests: `testPauseInsideCalledBlockResumesIntoCaller`, `testPauseInsideNestedCallsResumesThroughEveryCaller`, `testJumpFromResumedCalleeToSuspendedCallerRestartsIt`. |
| Pause inside a call made by hand-written PHP | — | A `wrapCall()` without its `$fromBlock` argument keeps the Python behavior: the continuation is dropped on resume. | Pre-0.1 flow files keep working. Test: `testLegacyCallWithoutCallingBlockKeepsPreviousResumeBehavior`. |
| Recursion | Limited by the Python recursion limit. | `_max_call_depth` (default 256) limits both nested calls and flows nested as activities. | Avoids exhausting the PHP stack. Tests: `testRecursiveCallStopsAtMaxCallDepth`, `testFlowUsingItselfAsActivityStopsAtMaxDepth`. |
| Identifiers | Sanitized by the modeler. | Block and step ids must not start with `_` (reserved context keys) or contain `.`. | Activity results and block callables live in the context. Test: `testIdsStartingWithUnderscoreAreRejected`. |
| Condition truthiness | Python truthiness (`"0"` is true). | PHP truthiness (`"0"` is false). | Return booleans from conditions. |
| YAML root | `striders:` | `blocks:` | Runner naming. Rename the key to run a `vvm_striders` flow. |
| Function references | Module-qualified (`module:` per step, `module:name`). | Root folder + name, path or callable name; `module` is rejected. | No Odoo addons in Runner. |
| Control-flow signals | Exception classes. | Per-run random tokens in exception messages. | Activities need no Runner classes. |

## Partially supported

| Behavior | Status |
|---|---|
| YAML | A subset: no anchors/aliases/tags, no multi-line flow collections, no multi-line plain scalars (explicit errors). Timestamps and sexagesimal numbers stay strings. A few inputs that PyYAML rejects (`a: b:c`, `b: - foo`) are read as strings. |
| Flows used as conditions | Not scoped like activities; use flows as activities only. |
| Function signature checks | Enforced for the flow entry point (`array &$context`); activities and conditions are called with the context without a signature check. |
| Diagnostics | Messages and logs, without the stable error codes of `FunctionResolutionError`. |

## Missing (later development)

- Persistence layer: storing/sanitizing a paused context (`_context_for_storage`),
  re-applying the initial context on resume, Job records. Runner leaves this to the caller.
- `_progress` reporting key.
- YAML files used directly as activities (compile them to PHP first, as in
  `testNestedFlowActivityRestoresParentBlocks`).
- Iteration limits for jump loops.
- Modeler features of `vvm_striders`: diagnostics with codes, simulation, legacy import, releases.
