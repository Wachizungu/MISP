<?php
$append = [];
if ($data['module_type'] == 'trigger') {
    $append[] = [
        'element' => 'Workflows/executionOrderWidget',
        'element_params' => [
            'trigger' => $data
        ]
    ];
}

// $data['params'] = JsonTool::encode($data['params']);
echo $this->element(
    'genericElements/SingleViews/single_view',
    [
        'title' => 'Workflow module view',
        'data' => $data,
        'fields' => [
            [
                'key' => __('ID'),
                'path' => 'id'
            ],
            [
                'key' => __('Name'),
                'path' => 'name',
                'class' => 'bold',
                'type' => 'custom',
                'function' => function ($row) {
                    return sprintf('<i class="fa-fw %s"></i> %s', $this->FontAwesome->getClass($row['icon']), h($row['name']));
                }
            ],
            [
                'key' => __('Module Type'),
                'path' => 'module_type'
            ],
            [
                'key' => __('Is MISP module'),
                'type' => 'boolean',
                'path' => 'is_misp_module'
            ],
            [
                'key' => __('Description'),
                'path' => 'description'
            ],
            [
                'key' => __('Module Enabled'),
                'type' => 'boolean',
                'path' => 'disabled',
                'element' => 'boolean',
                'mapping' => [
                    true => '<i class="fas fa-times"></i>',
                    false => '<i class="fas fa-check"></i>'
                ],
            ],
            [
                'key' => __('Module Parameters'),
                'type' => 'json',
                'path' => 'params',
            ],
            [
                'key' => __('Workflow Execution Order'),
                'requirement' => $data['module_type'] == 'trigger',
                'type' => 'custom',
                'function' => function ($row) {
                    return $this->element('Workflows/executionOrder', ['trigger' => $row]);
                }
            ],
        ],
        'append' => $append
    ]
);

?>
