(function(blocks, element, components, blockEditor) {
    const el = element.createElement;
    const { SelectControl, Placeholder } = components;
    const { InspectorControls } = blockEditor;
    const { PanelBody } = components;

    blocks.registerBlockType('dbfb/form', {
        title: 'DB Form Builder',
        icon: 'feedback',
        category: 'widgets',
        keywords: ['form', 'contact', 'modulo', 'contatto'],
        
        attributes: {
            formId: {
                type: 'string',
                default: ''
            }
        },
        
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { formId } = attributes;
            
            const forms = dbfbBlock.forms || [];
            
            const onChangeForm = function(newFormId) {
                setAttributes({ formId: newFormId });
            };
            
            // Se nessun form selezionato, mostra placeholder
            if (!formId) {
                return el(Placeholder, {
                    icon: 'feedback',
                    label: 'DB Form Builder',
                    instructions: 'Seleziona un form da visualizzare'
                },
                    el(SelectControl, {
                        value: formId,
                        options: forms,
                        onChange: onChangeForm
                    })
                );
            }
            
            // Form selezionato - mostra anteprima
            const selectedForm = forms.find(f => f.value == formId);
            const formName = selectedForm ? selectedForm.label : 'Form #' + formId;
            
            return el('div', { className: 'dbfb-gutenberg-preview' },
                el(InspectorControls, {},
                    el(PanelBody, { title: 'Impostazioni Form', initialOpen: true },
                        el(SelectControl, {
                            label: 'Seleziona Form',
                            value: formId,
                            options: forms,
                            onChange: onChangeForm
                        })
                    )
                ),
                el('div', { 
                    style: { 
                        padding: '20px', 
                        background: '#f0f0f0', 
                        border: '1px dashed #ccc',
                        borderRadius: '4px',
                        textAlign: 'center'
                    } 
                },
                    el('span', { 
                        className: 'dashicons dashicons-feedback',
                        style: { fontSize: '30px', marginBottom: '10px', display: 'block' }
                    }),
                    el('strong', {}, formName),
                    el('p', { style: { margin: '5px 0 0', color: '#666', fontSize: '12px' } }, 
                        '[dbfb_form id="' + formId + '"]'
                    )
                )
            );
        },
        
        save: function() {
            // Rendering dinamico via PHP
            return null;
        }
    });
    
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.components,
    window.wp.blockEditor
);
