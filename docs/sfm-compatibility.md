# SFM Compatibility

Runner is an independent PHP implementation of the
[Sequential Flow Model](https://github.com/rafageist/book-sequential-flow-model).
Its semantic reference is the Python SFM execution runtime (core loop, wrappers
and job execution). The Python SFM modeler (canonical YAML, compiler, validator,
simulator) is the secondary reference for YAML shape and step semantics
(flow simulation notes, ADR-S03, ADR-I2-S01, ADR-I2-S02).

Status of v0.2.0; the execution semantics are unchanged since v0.1.0. Test names
refer to `tests/RunnerTest.php`.

## Terminology

| Term | Meaning in Runner |
|---|---|
| Flow | A callable `function (array &$context)`, a PHP file returning one, or a YAML definition compiled to one. |
| Block | A named, ordered sequence of steps (`blocks:` in YAML); its callable lives at `_<block_id>` while the flow runs. |
| Step | One entry of a block: `condition`, `context`, `call`, `activity`, `jump`, `pause`, optional `module`. |
| Module | A named folder of functions; `module:name` is a qualified function name. |

Context keys follow the same vocabulary: `_current_block_id`, `_flow_state.block`,
`_flow_stack[].block`, `_flow_blocks`.

## Supported

| Behavior | Notes | Tests |
|---|---|---|
| Shared mutable context | One array passed by reference to flows, activities and conditions. | `testRunUpdatesContext`, `testWrappersAndUpdateContextMutateSharedContext` |
| Ordered blocks and steps | Mappings keyed by id, in execution order; the first block is the entry; blocks do not fall through. | `testRunBusinessFixtureFlowUsesActivitiesAndBlocks`, `testListFormsOfBlocksAndStepsAreRejected` |
| Step order | condition → context → call → activity → jump → pause. | `testCallReturnsIntoCallingStepBeforeItsActivity`, `testFalseConditionSkipsEveryActionOfTheStep` |
| Condition gates the whole step, including `call` | Same as ADR-S03 (compiler 1.0.0). | `testFalseConditionSkipsEveryActionOfTheStep` |
| Activity result stored under the step id | `$context[<step_id>] = result`. | `testRunBusinessFixtureFlowUsesActivitiesAndBlocks` |
| `call` with return | Runs the target block from the target step, then the calling step continues with its activity, jump and pause. | `testCallReturnsIntoCallingStepBeforeItsActivity` |
| `jump` without return | Nearest invocation running the target block restarts at the target step; otherwise the target becomes the running block and call continuations are dropped. | `testJumpOutOfCalledBlockDropsCallContinuation`, `testJumpToCalledBlockRestartsNearestInvocationAndReturns`, `testJumpToCallerBlockRestartsCallerAtTarget`, `testFlowLoopHandlesJumpTokenAndContinuesWithSavedBlock` |
| Target resolution | `block.step`; `block.` = step 0; `.step` = current block; unknown targets fail at compile time. | `testShortTargetsUseCurrentBlockAndFirstStep`, `testInvalidTargetReferenceIsRejectedAtCompileTime` |
| Pause and resume (`_flow_state`) | Resume continues after the paused step. | `testRunPauseResumeFixtureFlowResumesFromSavedStep`, `testCheckPauseUsesSavedFlowState` |
| Pause at any call depth | See *Known divergences*: call continuations are persisted. | `testDeepCallContinuationsResumeFromJsonInFreshProcesses`, `testRepeatedPauseJumpAndErrorAcrossCallsResumeFromJson` |
| Pause requested by an activity | Pauses at that step; the result is not stored. | `testActivityPauseCheckpointsItsStepWithoutStoringResult` |
| `_autoresume` | Resumes immediately once. | `testRunAutoResumesWhenAutoresumeIsEnabled`, `testAutoresumeInsideCalledBlockReturnsIntoCaller` |
| Flow used as an activity | Own control scope; parent blocks (`_flow_blocks`), position and call stack restored; `_flow_state`/`_autoresume` and any other key kept as the activity left them. | `testNestedFlowActivityRestoresParentBlocks`, `testActivityClosuresUnderUnderscoreKeysPersist` |
| Pause inside a nested flow | Early return of the nested flow (result `null`); the parent continues (ADR-I2-S01). | `testPauseInsideNestedFlowIsAnEarlyReturn` |
| Module-qualified functions | Optional step `module`; `module:name` resolves only inside that module's folder, so equal names in different modules do not collide; steps without `module` keep unqualified resolution. `_importer($name, $module)` for activities. | `testModulesResolveEqualFunctionNamesWithoutCollision`, `testModuleReferenceNeverFallsBackOutsideItsModule`, `testModuleReferencesAreValidatedAtCompileTime`, `testFunctionMapUsesQualifiedNames` |
| Unqualified functions | PHP file under `_root_folder` / `DIV_RUNNER_ROOT_FOLDER`, then path, then callable name. | `testImporterPrefersFileOverSameNamedCallable`, `testRunYamlResolvesActivitiesUsingRuntimeRootFolder`, `testImporterAcceptsNamespacedCallableIdentifier` |
| Missing or failing functions | Run ends in `error` with a log entry naming the qualified function. | `testMissingActivityMarksErrorState`, `testModuleReferenceNeverFallsBackOutsideItsModule`, `testImporterHandlesRequireErrorAndLogsException` |
| Deterministic compilation | Same YAML ⇒ byte-identical PHP. | `testGeneratedCodeIsDeterministic` |
| Schema validation | Unknown flow/block/step keys and wrong types are rejected. | `testSchemaRejectsUnknownKeysAndWrongTypes` |
| YAML subset used by real flows | Block scalars, one-line flow collections, nested and indentless sequences, comments, quoted keys, YAML 1.1 scalar resolution as PyYAML `safe_load` (`yes`/`off` booleans, `0x1F`, `017`, `.5`). The 22 fixture flows of the Python modeler parse identically to PyYAML. | `testYamlParserSupportsBlockScalarsFlowCollectionsAndComments`, `testYamlParserMatchesPyYamlOnSequenceItemsAndScalars`, `testYamlParserRejectsMisindentedBlockScalarAndBadQuotes` |
| Errors do not leave resumable state | Any error, including setup errors and errors before a saved position is reached, clears `_flow_state`, `_flow_stack` and pending resume markers; business data and diagnostics stay, and the next run starts at the first block. | `testErrorInsideCalledBlockLeavesNoStaleCallFrames`, `testErrorBeforeSavedPositionIsConsumedRestartsAtFirstBlock`, `testSetupErrorOnResumeClearsSavedPosition`, `testErrorAfterCallReturnRestartsAtFirstBlock`, `testRepeatedPauseJumpAndErrorAcrossCallsResumeFromJson` |
| Storable context | After `run()` returns, the runner's helpers and block callables are removed; JSON serialization of user-supplied values is not guaranteed. | `testContextAfterRunHoldsOnlyData`, `testDeepCallContinuationsResumeFromJsonInFreshProcesses` |

## Known divergences

| Behavior | Python reference runtime | Runner | Reason |
|---|---|---|---|
| Pause inside a called block | Call return frames are Python frames and are not persisted: after resume the called block finishes and the flow ends (ADR-I2-S02). | Return frames are plain data in `_flow_stack`, stored with the context: after resume the called block finishes and control returns into every pending calling step, at any depth. | Product decision: a flow can pause in any step of any block. Tests: `testPauseInsideCalledBlockResumesIntoCaller`, `testPauseInsideNestedCallsResumesThroughEveryCaller`, `testDeepCallContinuationsResumeFromJsonInFreshProcesses`, `testRepeatedPauseJumpAndErrorAcrossCallsResumeFromJson`, `testJumpFromResumedCalleeToSuspendedCallerRestartsIt`. |
| Step without `module` | Resolved in the runtime's default module (with an info diagnostic). | Unqualified resolution: root folder, path, callable name. | No default module outside a framework. |
| Module location | Installed framework addons. | `_modules[<module>]` or `<root>/<module>`: plain folders. | Framework independence. |
| Recursion | Limited by the Python recursion limit. | `_max_call_depth` (default 256) limits both nested calls and flows nested as activities. | Avoids exhausting the PHP stack. Tests: `testRecursiveCallStopsAtMaxCallDepth`, `testFlowUsingItselfAsActivityStopsAtMaxDepth`. |
| Identifiers | Sanitized by the modeler. | Block and step ids must not start with `_` (reserved context keys) or contain `.`. | Activity results and block callables live in the context. Test: `testIdsStartingWithUnderscoreAreRejected`. |
| Condition truthiness | Python truthiness (`"0"` is true). | PHP truthiness (`"0"` is false). | Return booleans from conditions. |
| YAML root and shapes | Its own root key for blocks; list forms of blocks and steps are normalized as legacy input. | `blocks:` only; blocks and steps must be mappings. | Runner defines its public format; rename the root key to run a flow exported by the modeler. Test: `testListFormsOfBlocksAndStepsAreRejected`. |
| Control-flow signals | Exception classes. | Per-run random tokens in exception messages. | Activities need no Runner classes. |

## Partially supported

| Behavior | Status |
|---|---|
| YAML | A subset: no anchors/aliases/tags, no multi-line flow collections, no multi-line plain scalars (explicit errors). Timestamps and sexagesimal numbers stay strings. A few inputs that PyYAML rejects (`a: b:c`, `b: - foo`) are read as strings. |
| Flows used as conditions | Not scoped like activities; use flows as activities only. |
| Function signature checks | Enforced for the flow entry point (`array &$context`); activities and conditions are called with the context without a signature check. |
| Diagnostics | Messages and logs, without the stable error codes of the Python function resolver. |

## Called blocks versus nested flows

| | Block reached by `call` | Flow used as an activity |
|---|---|---|
| Scope | Same flow and control state. | Own invocation and control scope. |
| Pause inside it | Pauses the whole run; return frames stay in `_flow_stack`. | Ends the child invocation early; the activity result is `null`. |
| On the next run | Resumes after the paused step, then returns through every caller. | Nothing to resume; the parent already continued. |

Both behaviors are part of the v0.1.0 contract (the nested-flow rule follows
ADR-I2-S01). Tests: `testDeepCallContinuationsResumeFromJsonInFreshProcesses`,
`testPauseInsideNestedFlowIsAnEarlyReturn`.

## Missing (later development)

- Persistence layer: storing a paused context, re-applying the initial context on
  resume, job records. Runner leaves this to the caller; it removes its own helpers, but JSON serialization of user-supplied values is not guaranteed.
- `_progress` reporting key.
- YAML files used directly as activities (compile them to PHP first, as in
  `testNestedFlowActivityRestoresParentBlocks`).
- Iteration limits for jump loops.
- Modeler features: diagnostics with codes, simulation, legacy import, releases.
