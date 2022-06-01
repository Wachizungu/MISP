<?php
App::uses('AppModel', 'Model');
App::uses('WorkflowGraphTool', 'Tools');

class WorkflowDuplicatedModuleIDException extends Exception {}
class TriggerNotFoundException extends Exception {}

class Workflow extends AppModel
{
    public $recursive = -1;

    public $actsAs = [
        'AuditLog',
        'Containable',
    ];

    public $belongsTo = [
        'User' => [
            'className' => 'User',
            'foreignKey' => 'user_id',
        ],
        'Organisation' => [
            'className' => 'Organisation',
            'foreignKey' => 'org_id'
        ]
    ];

    public $validate = [
        'value' => [
            'stringNotEmpty' => [
                'rule' => ['stringNotEmpty']
            ]
        ],
        'uuid' => [
            'uuid' => [
                'rule' => 'uuid',
                'message' => 'Please provide a valid RFC 4122 UUID'
            ],
            'unique' => [
                'rule' => 'isUnique',
                'message' => 'The UUID provided is not unique',
                'required' => 'create'
            ]
        ],
        'data' => [
            'hasAcyclicGraph' => [
                'rule' => ['hasAcyclicGraph'],
                'message' => 'Cannot save a workflow containing a cycle',
            ],
            'MoreThanOneTriggerInstance' => [
                'rule' => ['MoreThanOneTriggerInstance'],
                'message' => 'Cannot save a workflow containing more than one instance of the same trigger',
            ]
        ]
    ];

    public $defaultContain = [
        // 'Organisation',
        // 'User'
    ];

    private $loaded_modules = [];
    private $loaded_classes = [];

    private $module_initialized = false;

    const CAPTURE_FIELDS = ['name', 'description', 'timestamp', 'data'];

    const MODULE_ROOT_PATH = APP . 'Model/WorkflowModules/';
    const REDIS_KEY_WORKFLOW_NAMESPACE = 'workflow';
    const REDIS_KEY_WORKFLOW_PER_TRIGGER = 'workflow:workflow_list:%s';
    const REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER = 'workflow:workflow_blocking_order_list:%s';
    const REDIS_KEY_TRIGGER_PER_WORKFLOW = 'workflow:trigger_list:%s';

    public function __construct($id = false, $table = null, $ds = null)
    {
        parent::__construct($id, $table, $ds);
        $this->workflowGraphTool = new WorkflowGraphTool();
    }

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        if (empty($this->data['Workflow']['data'])) {
            $this->data['Workflow']['data'] = [];
        }
        if (empty($this->data['Workflow']['timestamp'])) {
            $this->data['Workflow']['timestamp'] = time();
        }
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $result) {
            if (empty($result['Workflow']['data'])) {
                $result['Workflow']['data'] = '{}';
            }
            $results[$k]['Workflow']['data'] = JsonTool::decode($result['Workflow']['data']);
            if (!empty($result['Workflow']['id'])) {
                $trigger_ids = $this->__getTriggersIDPerWorkflow((int) $result['Workflow']['id']);
                $results[$k]['Workflow']['listening_triggers'] = $this->getModuleByID($trigger_ids);
            }
        }
        return $results;
    }

    public function beforeSave($options = [])
    {
        $this->data['Workflow']['data'] = JsonTool::encode($this->data['Workflow']['data']);
        return true;
    }

    public function afterSave($created, $options = [])
    {
        $this->updateListeningTriggers($this->data);
    }

    public function beforeDelete($cascade = true)
    {
        parent::beforeDelete($cascade);
        $workflow = $this->find('first', [ // $this->data is empty in afterDelete?!
            'recursive' => -1,
            'conditions' => ['Workflow.id' => $this->id]
        ]);
        $workflow['Workflow']['data'] = []; // Make sure not trigger are listening
        $this->workflowToDelete = $workflow;
    }

    public function afterDelete()
    {
        // $this->data is empty?!
        parent::afterDelete();
        $this->updateListeningTriggers($this->workflowToDelete);
    }

    protected function checkTriggerEnabled($trigger_id)
    {
        $filename = sprintf('Module_%s.php', preg_replace('/[^a-zA-Z0-9_]/', '_', Inflector::underscore($trigger_id)));
        $module_config = $this->__getClassFromModuleFiles('trigger', [$filename])['classConfigs'];
        // FIXME: Merge global configuration!
        return empty($module_config['disabled']);
    }

    protected function checkTriggerListenedTo($trigger_id)
    {
        return !empty($this->__getWorkflowsIDPerTrigger($trigger_id));
    }

    public function rebuildRedis($user)
    {
        $redis = $this->setupRedisWithException();
        $workflows = $this->fetchWorkflows($user);
        $keys = $redis->keys(Workflow::REDIS_KEY_WORKFLOW_NAMESPACE . ':*');
        $redis->delete($keys);
        foreach ($workflows as $wokflow) {
            $this->updateListeningTriggers($wokflow);
        }
    }

    /**
     * updateListeningTriggers 
     *  - Update the list of triggers that will be run this workflow
     *  - Update the list of workflows that are run by their triggers
     *  - Update the ordered list of workflows that are run by their triggers
     *
     * @param  array $workflow
     */
    public function updateListeningTriggers($workflow)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            $this->logException('Failed to setup redis ', $e);
            return false;
        }
        if (!is_array($workflow['Workflow']['data'])) {
            $workflow['Workflow']['data'] = JsonTool::decode($workflow['Workflow']['data']);
        }
        $original_trigger_list_id = $this->__getTriggersIDPerWorkflow((int)$workflow['Workflow']['id']);
        $new_node_trigger_list = $this->workflowGraphTool->extractTriggersFromWorkflow($workflow['Workflow']['data'], true);
        $new_node_trigger_list_per_id = Hash::combine($new_node_trigger_list, '{n}.data.id', '{n}');
        $new_trigger_list_id = array_keys($new_node_trigger_list_per_id);
        $trigger_to_remove = array_diff($original_trigger_list_id, $new_trigger_list_id);
        $trigger_to_add = array_diff($new_trigger_list_id, $original_trigger_list_id);
        if (!empty($trigger_to_remove)) {
            $pipeline = $redis->multi();
            foreach ($trigger_to_remove as $trigger_id) {
                $pipeline->sRem(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
                $pipeline->sRem(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow['Workflow']['id']), $trigger_id);
                $pipeline->lRem(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), $workflow['Workflow']['id'], 0);
            }
            $pipeline->exec();
        }
        if (!empty($trigger_to_add)) {
            $pipeline = $redis->multi();
            foreach ($trigger_to_add as $trigger_id) {
                if (
                    $this->workflowGraphTool->triggerHasNonBlockingPath($new_node_trigger_list_per_id[$trigger_id])
                    || $this->workflowGraphTool->triggerHasBlockingPath($new_node_trigger_list_per_id[$trigger_id])
                ) {
                    $pipeline->sAdd(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
                    $pipeline->sAdd(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow['Workflow']['id']), $trigger_id);
                    if ($this->workflowGraphTool->triggerHasBlockingPath($new_node_trigger_list_per_id[$trigger_id])) {
                        $pipeline->rPush(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), $workflow['Workflow']['id']);
                    }
                }
            }
            $pipeline->exec();
        }
    }

    /**
     * __getWorkflowsIDPerTrigger Get list of workflow IDs listening to the specified trigger
     *
     * @param  string $trigger_id
     * @return bool|array
     */
    private function __getWorkflowsIDPerTrigger($trigger_id): array
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        $list = $redis->sMembers(sprintf(Workflow::REDIS_KEY_WORKFLOW_PER_TRIGGER, $trigger_id));
        return !empty($list) ? $list : [];
    }

    /**
     * __getOrderedWorkflowsPerTrigger Get list of workflow IDs in the execution order for the specified trigger
     *
     * @param  string $trigger_id
     * @return bool|array
     */
    private function __getOrderedWorkflowsPerTrigger($trigger_id)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        return $redis->lRange(sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id), 0, -1);
    }

    /**
     * __getTriggersIDPerWorkflow Get list of trigger name running to the specified workflow
     *
     * @param  int $workflow_id
     * @return bool|array
     */
    private function __getTriggersIDPerWorkflow(int $workflow_id)
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            return false;
        }
        return $redis->sMembers(sprintf(Workflow::REDIS_KEY_TRIGGER_PER_WORKFLOW, $workflow_id));
    }

    /**
     * saveBlockingWorkflowExecutionOrder Save the workflow execution order for the provided trigger
     *
     * @param  string $trigger_id
     * @param  array $workflows List of workflow IDs in priority order
     * @return bool
     */
    public function saveBlockingWorkflowExecutionOrder($trigger_id, array $workflow_order): bool
    {
        try {
            $redis = $this->setupRedisWithException();
        } catch (Exception $e) {
            $this->logException('Failed to setup redis ', $e);
            return false;
        }
        $key = sprintf(Workflow::REDIS_KEY_WORKFLOW_ORDER_PER_BLOCKING_TRIGGER, $trigger_id);
        $pipeline = $redis->multi();
        $pipeline->del($key);
        foreach ($workflow_order as $workflow_id) {
            $pipeline->rpush($key, (string)$workflow_id);
        }
        $pipeline->exec();
        return true;
    }

    /**
     * buildACLConditions Generate ACL conditions for viewing the workflow
     *
     * @param  array $user
     * @return array
     */
    public function buildACLConditions(array $user)
    {
        $conditions = [];
        if (!$user['Role']['perm_site_admin']) {
            $conditions['Workflow.org_id'] = $user['org_id'];
        }
        return $conditions;
    }

    public function canEdit(array $user, array $workflow)
    {
        if ($user['Role']['perm_site_admin']) {
            return true;
        }
        if (empty($workflow['Workflow'])) {
            return __('Could not find associated workflow');
        }
        if ($workflow['Workflow']['user_id'] != $user['id']) {
            return __('Only the creator user of the workflow can modify it');
        }
        return true;
    }

    /**
     * attachWorkflowsToTriggers Collect the workflows listening to this trigger
     *
     * @param  array $user
     * @param  array $triggers
     * @param  bool $group_per_blocking Whether or not the workflows should be grouped together if they have a blocking path set
     * @return array
     */
    public function attachWorkflowsToTriggers(array $user, array $triggers, bool $group_per_blocking=true): array
    {
        $all_workflow_ids = [];
        $workflows_per_trigger = [];
        $ordered_workflows_per_trigger = [];
        foreach ($triggers as $trigger) {
            $workflow_ids_for_trigger = $this->__getWorkflowsIDPerTrigger($trigger['id']);
            $workflows_per_trigger[$trigger['id']] = $workflow_ids_for_trigger;
            $ordered_workflows_per_trigger[$trigger['id']] = $this->__getOrderedWorkflowsPerTrigger($trigger['id']);
            foreach ($workflow_ids_for_trigger as $id) {
                $all_workflow_ids[$id] = true;
            }
        }
        $all_workflow_ids = array_keys($all_workflow_ids);
        $workflows = $this->fetchWorkflows($user, [
            'conditions' => [
                'Workflow.id' => $all_workflow_ids,
            ],
            'fields' => ['*'],
            'contain' => ['Organisation' => ['fields' => ['*']]],
        ]);
        $workflows = Hash::combine($workflows, '{n}.Workflow.id', '{n}');
        foreach ($triggers as $i => $trigger) {
            $workflow_ids = $workflows_per_trigger[$trigger['id']];
            $ordered_workflow_ids = $ordered_workflows_per_trigger[$trigger['id']];
            $triggers[$i]['Workflows'] = [];
            foreach ($workflow_ids as $workflow_id) {
                $triggers[$i]['Workflows'][] = $workflows[$workflow_id];
            }
            if (!empty($group_per_blocking)) {
                $triggers[$i]['GroupedWorkflows'] = $this->groupWorkflowsPerBlockingType($triggers[$i]['Workflows'], $trigger['id'], $ordered_workflow_ids);
            }
        }
        return $triggers;
    }

    public function fetchWorkflowsForTrigger($user, $trigger_id, $filterDisabled=false): array
    {
        $workflow_ids_for_trigger = $this->__getWorkflowsIDPerTrigger($trigger_id);
        $conditions = [
            'Workflow.id' => $workflow_ids_for_trigger,
        ];
        if (!empty($filterDisabled)) {
            $conditions['Workflow.enabled'] = true;
        }
        $workflows = $this->fetchWorkflows($user, [
            'conditions' => $conditions,
            'fields' => ['*'],
            'contain' => ['Organisation' => ['fields' => ['*']], 'User' => ['Role']],
        ]);
        return $workflows;
    }

    /**
     * getExecutionOrderForTrigger Generate the execution order for the provided trigger
     *
     * @param  array $user
     * @param  array $trigger
     * @return array
     */
    public function getExecutionOrderForTrigger(array $user, array $trigger, $filterDisabled=false): array
    {
        if (empty($trigger)) {
            return ['blocking' => [], 'non-blocking' => [] ];
        }
        $workflows = $this->fetchWorkflowsForTrigger($user, $trigger['id'], $filterDisabled);
        $ordered_workflow_ids = $this->__getOrderedWorkflowsPerTrigger($trigger['id']);
        return $this->groupWorkflowsPerBlockingType($workflows, $trigger['id'], $ordered_workflow_ids);
    }

    /**
     * groupWorkflowsPerBlockingType Group workflows together if they have a blocking path set or not. Also, sort the blocking list based on execution order
     *
     * @param  array $workflows
     * @param  string $trigger_id The trigger for which we should decide if it's blocking or not
     * @param  array $ordered_workflow_ids If provided, will sort the blocking workflows based on the workflow_id order in of the provided list
     * @return array
     */
    public function groupWorkflowsPerBlockingType(array $workflows, $trigger_id, $ordered_workflow_ids=false): array
    {
        $groupedWorkflows = [
            'blocking' => [],
            'non-blocking' => [],
        ];
        foreach ($workflows as $workflow) {
            foreach ($workflow['Workflow']['data'] as $block) {
                if ($block['data']['id'] == $trigger_id) {
                    if ($this->workflowGraphTool->triggerHasBlockingPath($block)) {
                        $order_index = array_search($workflow['Workflow']['id'], $ordered_workflow_ids);
                        $groupedWorkflows['blocking'][$order_index] = $workflow;
                    }
                    if ($this->workflowGraphTool->triggerHasNonBlockingPath($block)) {
                        $groupedWorkflows['non-blocking'][] = $workflow;
                    }
                }
            }
        }
        ksort($groupedWorkflows['blocking']);
        return $groupedWorkflows;
    }

    /**
     * isGraphAcyclic Return if the graph is acyclic or not
     *
     * @param array $graphData
     * @return boolean
     */
    public function hasAcyclicGraph(array $workflow): bool
    {
        $graphData = !empty($workflow['Workflow']) ? $workflow['Workflow']['data'] : $workflow['data'];
        $cycles = [];
        $isAcyclic = $this->workflowGraphTool->isAcyclic($graphData, $cycles);
        return $isAcyclic;
    }

    /**
     * MoreThanOneTriggerInstance Return if the graph contain more than one instance of the same trigger
     *
     * @param array $graphData
     * @return boolean
     */
    public function MoreThanOneTriggerInstance(array $workflow): bool
    {
        $graphData = !empty($workflow['Workflow']) ? $workflow['Workflow']['data'] : $workflow['data'];
        $triggers = $this->workflowGraphTool->extractTriggersFromWorkflow($graphData, true);
        $visitedTriggerIDs = [];
        foreach ($triggers as $trigger) {
            if (!empty($visitedTriggerIDs[$trigger['data']['id']])) {
                return false;
            }
            $visitedTriggerIDs[$trigger['data']['id']] = true;
        }
        return true;
    }

    /**
     * executeWorkflowsForTrigger
     *
     * @param string $trigger_id
     * @param array $data
     * @param array $errors
     * @return boolean True if the execution for the blocking path was a success
     * @throws TriggerNotFoundException
     */
    public function executeWorkflowsForTrigger($trigger_id, array $data, array &$blockingErrors=[]): bool
    {
        $this->loadAllWorkflowModules();

        $user = ['Role' => ['perm_site_admin' => true]];
        if (empty($this->loaded_modules['trigger'][$trigger_id])) {
            throw new TriggerNotFoundException(__('Unknown trigger `%s`', $trigger_id));
        }
        $trigger = $this->loaded_modules['trigger'][$trigger_id];
        if (!empty($trigger['disabled'])) {
            return true;
        }
        
        $blockingPathExecutionSuccess = true;
        $workflowExecutionOrder = $this->getExecutionOrderForTrigger($user, $trigger, true);
        $orderedBlockingWorkflows = $workflowExecutionOrder['blocking'];
        $orderedDeferredWorkflows = $workflowExecutionOrder['non-blocking'];
        foreach ($orderedBlockingWorkflows as $workflow) {
            $walkResult = [];
            $continueExecution = $this->walkGraph($workflow, $trigger_id, 'blocking', $data, $blockingErrors, $walkResult);
            $this->loadLog()->createLogEntry($this->User->getAuthUser($workflow['User']['id']), 'walkGraph', 'Workflow', $workflow['Workflow']['id'], __('Executed blocking path for trigger `%s`', $trigger_id), $this->digestExecutionResult($walkResult));
            if (!$continueExecution) {
                $blockingPathExecutionSuccess = false;
                break;
            }
        }
        foreach ($orderedDeferredWorkflows as $workflow) {
            $deferredErrors = [];
            $walkResult = [];
            $this->walkGraph($workflow, $trigger_id, 'non-blocking',  $data, $deferredErrors, $walkResult);
            $this->loadLog()->createLogEntry($this->User->getAuthUser($workflow['User']['id']), 'walkGraph', 'Workflow', $workflow['Workflow']['id'], __('Executed non-blocking path for trigger `%s`', $trigger_id), $this->digestExecutionResult($walkResult));
        }
        return $blockingPathExecutionSuccess;
    }

    // public function executeWorkflow($id, array $data=[])
    // {
    //     $user = ['Role' => ['perm_site_admin' => true]];
    //     $workflow = $this->fetchWorkflow($user, $id);
    //     $trigger_ids = $this->workflowGraphTool->extractTriggersFromWorkflow($workflow['Workflow']['data'], false);
    //     foreach ($trigger_ids as $trigger_id) {
    //         $this->walkGraph($workflow, $trigger_id, null, $data);
    //     }
    // }

    /**
     * walkGraph Walk the graph for the provided trigger and execute each nodes
     *
     * @param array $workflow The worflow to walk
     * @param string $trigger_id The ID of the trigger to start from
     * @param bool|null $for_path If provided, execute the workflow for the provided path. If not provided, execute the worflow regardless of the path
     * @param array $data
     * @param array $errors
     * @return boolean If all module returned a successful response
     */
    private function walkGraph(array $workflow, $trigger_id, $for_path=null, array $data=[], array &$errors=[], array &$walkResult=[]): bool
    {
        $walkResult = [
            'Blocked paths' => [],
            'Executed nodes' => [],
            'Nodes that stopped execution' => [],
        ];
        $workflowUser = $this->User->getAuthUser($workflow['Workflow']['user_id'], true);
        $roamingData = $this->workflowGraphTool->getRoamingData($workflowUser, $data);
        $graphData = !empty($workflow['Workflow']) ? $workflow['Workflow']['data'] : $workflow['data'];
        $startNode = $this->workflowGraphTool->getNodeIdForTrigger($graphData, $trigger_id);
        if ($startNode  == -1) {
            return false;
        }
        $graphWalker = $this->workflowGraphTool->getWalkerIterator($graphData, $this, $startNode, $for_path, $roamingData);
        $preventExecutionForPaths = [];
        foreach ($graphWalker as $graphNode) {
            $node = $graphNode['node'];
            foreach ($preventExecutionForPaths as $path_to_block) {
                if ($path_to_block == array_slice($graphNode['path_list'], 0, count($path_to_block))) {
                    $walkResult['Blocked paths'][] = $graphNode['path_list'];
                    continue 2;
                }
            }
            $nodeError = [];
            $success = $this->__executeNode($node, $roamingData, $nodeError);
            $walkResult['Executed nodes'][] = $node;
            if (empty($success)) {
                $walkResult['Nodes that stopped execution'][] = $node;
                if ($graphNode['path_type'] == 'blocking') {
                    if (!empty($nodeError)) {
                        $errors[] = __(
                            'Node `%s` (%s) from Workflow `%s` (%s) returned the following error: %s',
                            $node['data']['id'],
                            $node['id'],
                            $workflow['Workflow']['name'],
                            $workflow['Workflow']['id'],
                            implode(', ', $nodeError)
                        );
                    }
                    return false; // Node stopped execution for blocking path
                }
                if ($graphNode['path_type'] == 'non-blocking') {
                    $preventExecutionForPaths[] = $graphNode['path_list']; // Paths down the chain for this path should not be executed
                }
            }
        }
        return true;
    }

    public function __executeNode($node, WorkflowRoamingData $roamingData, array &$errors): bool
    {
        $moduleClass = $this->getModuleClass($node);
        if (!is_null($moduleClass)) {
            try {
                $success = $moduleClass->exec($node, $roamingData, $errors);
            } catch (Exception $e) {
                $message = sprintf(__('Error while executing module: %s'), $e->getMessage());
                $this->logException(__('Error while executing module %s', $node['data']['id']), $e);
                return false;
            }
        }
        return $success;
    }

    private function digestExecutionResult(array $walkResult)
    {
        if (empty($walkResult['Nodes that stopped execution'])) {
            return __('All nodes executed.');
        }
        $str = [];
        foreach ($walkResult['Nodes that stopped execution'] as $node) {
            $str[] = __('Node `%s` (%s) stopped execution.', $node['data']['id'], $node['id']);
        }
        return implode(', ', $str);
    }

    public function getModuleClass($node)
    {
        $this->loadAllWorkflowModules();
        $moduleClass = $this->loaded_classes[$node['data']['module_type']][$node['data']['id']] ?? null;
        return $moduleClass;
    }

    public function attachNotificationToModules(array $user, array $modules, array $workflow): array
    {
        foreach ($modules as $moduleType => $modulesByType) {
            foreach ($modulesByType as $i => $module) {
                $modules[$moduleType][$i]['notifications'] = !empty($module['notifications']) ? $module['notifications'] : [
                    'error' => [],
                    'warning' => [],
                    'info' => [],
                ];
            }
        }
        $triggers = $modules['blocks_trigger'];
        foreach ($triggers as $i => $trigger) {
            $blockingExecutionOrder = $this->getExecutionOrderForTrigger($user, $trigger, true)['blocking'];
            $blockingExecutionOrderIDs = Hash::extract($blockingExecutionOrder, '{n}.Workflow.id');
            $indexInExecutionPath = array_search($workflow['Workflow']['id'], $blockingExecutionOrderIDs);
            $effectiveBlockingExecutionOrder = array_slice($blockingExecutionOrder, 0, $indexInExecutionPath);
            $details = [];
            foreach ($effectiveBlockingExecutionOrder as $workflow) {
                $details[] = sprintf('[%s] %s', h($workflow['Workflow']['id']), h($workflow['Workflow']['name']));
            }
            if (!empty($effectiveBlockingExecutionOrder)) {
                $modules['blocks_trigger'][$i]['notifications']['warning'][] = [
                    'text' => __('%s blocking worflows are executed before this trigger.', count($effectiveBlockingExecutionOrder)),
                    'description' => __('The blocking path of this trigger might not be executed. If any of the blocking workflows stop the propagation, the blocking path of this trigger will not be executed. Nevertheless, the deferred path will always be executed.'),
                    'details' => $details,
                ];
            }
        }
        return $modules;
    }

    public function loadAllWorkflowModules()
    {
        if ($this->module_initialized) {
            return;
        }
        $phpModuleFiles = Workflow::__listPHPModuleFiles();
        foreach ($phpModuleFiles as $type => $files) {
            $classModuleFromFiles = $this->__getClassFromModuleFiles($type, $files);
            foreach ($classModuleFromFiles['classConfigs'] as $i => $config) {
                $classModuleFromFiles['classConfigs'][$i]['module_type'] = $type;
            }
            $this->loaded_modules[$type] = $classModuleFromFiles['classConfigs'];
            $this->loaded_classes[$type] = $classModuleFromFiles['instancedClasses'];
        }
        $superUser = ['Role' => ['perm_site_admin' => true]];
        $modules_from_service = $this->__getModulesFromModuleService($superUser) ?? [];
        $misp_module_class = $this->__getClassForMispModule($modules_from_service);
        $misp_module_configs = [];
        foreach ($misp_module_class as $i => $module_class) {
            $misp_module_configs[$i] = $module_class->getConfig();
            $misp_module_configs[$i]['module_type'] = 'action';
        }
        $this->loaded_modules['action'] = array_merge($this->loaded_modules['action'], $misp_module_configs);
        $this->loaded_classes['action'] = array_merge($this->loaded_classes['action'], $misp_module_class);
        $this->__mergeGlobalConfigIntoLoadedModules();
        $this->module_initialized = true;
    }

    private function __mergeGlobalConfigIntoLoadedModules()
    {
        /* FIXME: Delete `disabled` entry. This is for testing while we wait for module settings */
        array_walk($this->loaded_modules['trigger'], function (&$trigger) {
            $module_enabled = !in_array($trigger['id'], ['publish', 'new-attribute']);
            $trigger['html_template'] = !empty($trigger['html_template']) ? $trigger['html_template'] : 'trigger';
            $trigger['disabled'] = $module_enabled;
            $this->loaded_classes['trigger'][$trigger['id']]->disabled = $module_enabled;
            $this->loaded_classes['trigger'][$trigger['id']]->html_template = !empty($trigger['html_template']) ? $trigger['html_template'] : 'trigger';
        });
        array_walk($this->loaded_modules['logic'], function (&$logic) {
            $module_enabled = true;
            $logic['disabled'] = $module_enabled;
            $this->loaded_classes['logic'][$logic['id']]->disabled = $module_enabled;
        });
        array_walk($this->loaded_modules['action'], function (&$action) {
            $module_enabled = !in_array($action['id'], ['push-zmq', 'slack-message', 'mattermost-message', 'add-tag', 'writeactions', 'enrich-event', 'testaction', ]);
            $action['disabled'] = $module_enabled;
            $this->loaded_classes['action'][$action['id']]->disabled = $module_enabled;
        });
        /* FIXME: end */
    }

    private function __getEnabledModulesFromModuleService($user)
    {
        if (empty($this->Module)) {
            $this->Module = ClassRegistry::init('Module');
        }
        $enabledModules = $this->Module->getEnabledModules($user, null, 'Action');
        $misp_module_config = empty($enabledModules) ? false : $enabledModules;
        return $misp_module_config;
    }

    private function __getModulesFromModuleService()
    {
        if (empty($this->Module)) {
            $this->Module = ClassRegistry::init('Module');
        }
        $modules = $this->Module->getModules('Action');
        foreach ($modules as $i => $temp) {
            if (!isset($temp['meta']['module-type']) || !in_array('action', $temp['meta']['module-type'])) {
                unset($modules[$i]);
            }
        }
        return $modules;
    }

    private function __getClassForMispModule($misp_module_configs)
    {
        $filepathMispModule = sprintf('%s/%s', Workflow::MODULE_ROOT_PATH, 'Module_misp_module.php');
        $className = 'Module_misp_module';
        $reflection = null;
        try {
            require_once($filepathMispModule);
            try {
                $reflection = new \ReflectionClass($className);
            } catch (\ReflectionException $e) {
                return $e->getMessage();
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
        $moduleClasses = [];
        foreach ($misp_module_configs as $moduleConfig) {
            $mainClass = $reflection->newInstance($moduleConfig);
            if ($mainClass->checkLoading() === 'The Factory Must Grow') {
                $moduleClasses[$mainClass->id] = $mainClass;
            }
        }
        return $moduleClasses;
    }

    /**
     * __listPHPModuleFiles List all PHP modules files
     *
     * @param boolean|array $targetDir If provided, will only collect files from that directory
     * @return array
     */
    private static function __listPHPModuleFiles($targetDir=false): array
    {
        $dirs = ['trigger', 'logic', 'action'];
        if (!empty($targetDir)) {
            $dirs = $targetDir;
        }
        $files = [];
        foreach ($dirs as $dir) {
            $folder = new Folder(Workflow::MODULE_ROOT_PATH . $dir);
            $filesInFolder = $folder->find('.*\.php', true);
            $files[$dir] = array_diff($filesInFolder, ['..', '.']);
            if ($dir == 'action') { // No custom module for the triggers
                $customFolder = new Folder(Workflow::MODULE_ROOT_PATH . $dir . '/Custom');
                $filesInCustomFolder = $customFolder->find('.*\.php', true);
                $filesInCustomFolder = array_map(function($file) {
                    return 'Custom/' . $file;
                }, $filesInCustomFolder);
                $files[$dir] = array_merge($filesInFolder, array_diff($filesInCustomFolder, ['..', '.']));
            }
        }
        return $files;
    }

    private function __getClassFromModuleFiles($type, $files)
    {
        $instancedClasses = [];
        $classConfigs = [];
        foreach ($files as $filename) {
            $filepath = sprintf('%s%s/%s', Workflow::MODULE_ROOT_PATH, $type, $filename);
            $instancedClass = $this->__getClassFromModuleFile($filepath);
            if (is_string($instancedClass)) {
                $message = sprintf(__('Error while trying to load module: %s'), $instancedClass);
                $this->__logError($filename, $message);
            }
            if (!empty($classConfigs[$instancedClass->id])) {
                throw new WorkflowDuplicatedModuleIDException(__('Module %s has already been defined', $instancedClass->id));
            }
            $classConfigs[$instancedClass->id] = $instancedClass->getConfig();
            $instancedClasses[$instancedClass->id] = $instancedClass;
        }
        return [
            'classConfigs' => $classConfigs,
            'instancedClasses' => $instancedClasses,
        ];
    }

    private function __logError($id, $message)
    {
        $this->Log = ClassRegistry::init('Log');
        $this->Log->createLogEntry('SYSTEM', 'load_module', 'Workflow', $id, $message);
        return false;
    }

    /**
     * getProcessorClass
     *
     * @param  string $filePath
     * @param  string $processorMainClassName
     * @return object|string Object loading success, string containing the error if failure
     */
    private function __getClassFromModuleFile($filepath)
    {
        $className = explode('/', $filepath);
        $className = str_replace('.php', '', $className[count($className)-1]);
        try {
            require_once($filepath);
            try {
                $reflection = new \ReflectionClass($className);
            } catch (\ReflectionException $e) {
                $this->logException(__('Could not load module for path %s', $filepath), $e);
                return false;
            }
            $mainClass = $reflection->newInstance();
            if ($mainClass->checkLoading() === 'The Factory Must Grow') {
                return $mainClass;
            }
        } catch (Exception $e) {
            $this->logException(__('Could not load module for path %s', $filepath), $e);
            return false;
        }
    }

    public function getModulesByType($module_type=false): array
    {
        $this->loadAllWorkflowModules();

        $blocks_trigger = $this->loaded_modules['trigger'];
        $blocks_logic = $this->loaded_modules['logic'];
        $blocks_action = $this->loaded_modules['action'];

        ksort($blocks_trigger);
        ksort($blocks_logic);
        ksort($blocks_action);
        $blocks_trigger = array_values($blocks_trigger);
        $blocks_logic = array_values($blocks_logic);
        $blocks_action = array_values($blocks_action);
        $modules = [
            'blocks_trigger' => $blocks_trigger,
            'blocks_logic' => $blocks_logic,
            'blocks_action' => $blocks_action,
        ];
        if (!empty($module_type)) {
            if (!empty($modules[$module_type])) {
                return $modules['block_' . $module_type];
            } else {
                return [];
            }
        }
        return $modules;
    }

    public function getModules(): array
    {
        $modulesByType = $this->getModulesByType();
        return array_merge($modulesByType['blocks_trigger'], $modulesByType['blocks_logic'], $modulesByType['blocks_action']);
    }

    /**
     * getModules Return the module from the provided ID
     *
     * @param string|array $module_ids
     * @return array
     */
    public function getModuleByID($module_ids): array
    {
        $returnAString = false;
        if (!is_array($module_ids)) {
            $returnAString = true;
            $module_ids = [$module_ids];
        }
        $matchingModules = [];
        $modules = $this->getModules();
        foreach ($modules as $module) {
            if (in_array($module['id'], $module_ids)) {
                $matchingModules[] = $module;
            }
        }
        if (empty($matchingModules)) {
            return [];
        }
        return $returnAString ? $matchingModules[0] : $matchingModules;
    }

    /**
     * fetchWorkflows ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  array $options
     * @param  bool  $full
     * @return array
     */
    public function fetchWorkflows(array $user, array $options = array(), $full = false)
    {
        $params = array(
            'conditions' => $this->buildACLConditions($user),
            'contain' => $this->defaultContain,
            'recursive' => -1
        );
        if ($full) {
            $params['recursive'] = 1;
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = !empty($options['group']) ? $options['group'] : false;
        }
        if (isset($options['contain'])) {
            $params['contain'] = !empty($options['contain']) ? $options['contain'] : [];
        }
        if (isset($options['order'])) {
            $params['order'] = !empty($options['order']) ? $options['order'] : [];
        }
        $workflows = $this->find('all', $params);
        return $workflows;
    }

    /**
     * fetchWorkflow ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  int|string $id
     * @param  bool $throwErrors
     * @return array
     */
    public function fetchWorkflow(array $user, $id, bool $throwErrors = true)
    {
        $options = [];
        if (is_numeric($id)) {
            $options = ['conditions' => ["Workflow.id" => $id]];
        } elseif (Validation::uuid($id)) {
            $options = ['conditions' => ["Workflow.uuid" => $id]];
        } else {
            if ($throwErrors) {
                throw new NotFoundException(__('Invalid workflow'));
            }
            return [];
        }
        $workflow = $this->fetchWorkflows($user, $options);
        if (empty($workflow)) {
            throw new NotFoundException(__('Invalid workflow'));
        }
        return $workflow[0];
    }

    /**
     * editWorkflow Edit a worflow
     *
     * @param  array $user
     * @param  array $workflow
     * @return array Any errors preventing the edition
     */
    public function editWorkflow(array $user, array $workflow)
    {
        $errors = array();
        if (!isset($workflow['Workflow']['uuid'])) {
            $errors[] = __('Workflow doesn\'t have an UUID');
            return $errors;
        }
        $existingWorkflow = $this->fetchWorkflow($user, $workflow['Workflow']['id']);
        $workflow['Workflow']['id'] = $existingWorkflow['Workflow']['id'];
        unset($workflow['Workflow']['timestamp']);
        $errors = $this->__saveAndReturnErrors($workflow, ['fieldList' => self::CAPTURE_FIELDS], $errors);
        return $errors;
    }

    /**
     * fetchWorkflow ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  int|string $id
     * @param  bool $enable
     * @param  bool $throwErrors
     * @return array
     */
    public function toggleWorkflow(array $user, $id, $enable=true, bool $throwErrors=true)
    {
        $errors = array();
        $workflow = $this->fetchWorkflow($user, $id, $throwErrors);
        $workflow['Workflow']['enabled'] = $enable;
        $errors = $this->__saveAndReturnErrors($workflow, ['fieldList' => ['enabled']], $errors);
        return $errors;
    }

    private function __saveAndReturnErrors($data, $saveOptions = [], $errors = [])
    {
        $saveSuccess = $this->save($data, $saveOptions);
        if (!$saveSuccess) {
            foreach ($this->validationErrors as $validationError) {
                $errors[] = $validationError[0];
            }
        }
        return $errors;
    }
    
}