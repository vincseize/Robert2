import './index.scss';
import Icon from '@/components/Icon/index';

// @vue/component
export default {
    name: 'TabButton',
    props: {
        title: { type: String, required: true },
        icon: { type: String, default: null },
        disabled: { type: Boolean, default: false },
        warning: { type: Boolean, default: false },
        active: { type: Boolean, default: false },
    },
    methods: {
        handleClick() {
            if (this.disabled) {
                return;
            }
            this.$emit('click');
        },
    },
    render() {
        const { title, icon, disabled, warning, active, handleClick } = this;

        const className = ['TabButton', {
            'TabButton--selected': active,
            'TabButton--disabled': disabled,
            'TabButton--warning': warning,
        }];

        return (
            <li role="tab" class={className} onClick={handleClick}>
                {icon && <Icon name={icon} class="TabButton__icon" />}
                {title}
            </li>
        );
    },
};
