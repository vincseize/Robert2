import './index.scss';
import Illustration from './assets/illustration.svg?inline';

// @vue/component
export default {
    name: 'CriticalError',
    props: {
        message: { type: String, default: undefined },
    },
    render() {
        const { $t: __, message } = this;

        return (
            <div class="CriticalError">
                <Illustration class="CriticalError__illustration" />
                <p class="CriticalError__message">{message ?? __('errors.critical')}</p>
                <a class="CriticalError__refresh button" href="">{__('refresh-page')}</a>
            </div>
        );
    },
};