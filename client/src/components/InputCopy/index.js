import './index.scss';
import Button from '@/components/Button';

// @vue/component
export default {
    name: 'InputCopy',
    props: {
        value: {
            type: [String, Number],
            required: true,
        },
    },
    data() {
        return {
            isCopied: false,
        };
    },
    methods: {
        // ------------------------------------------------------
        // -
        // -    Handlers
        // -
        // ------------------------------------------------------

        handleFocus() {
            this.selectInputContent();
        },

        handleCopy() {
            const cancelSelection = this.selectInputContent();
            if (!document.execCommand('copy')) {
                return;
            }
            cancelSelection();

            // - Affiche l'état "Copié !" et le revert au bout de 2 secondes.
            setTimeout(() => { this.isCopied = false; }, 2000);
            this.isCopied = true;
        },

        // ------------------------------------------------------
        // -
        // -    Internal methods
        // -
        // ------------------------------------------------------

        selectInputContent() {
            const range = document.createRange();
            range.selectNode(this.$refs.input);

            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);

            return () => { window.getSelection().removeAllRanges(); };
        },
    },
    render() {
        const {
            $t: __,
            value,
            isCopied,
            handleCopy,
            handleFocus,
        } = this;

        return (
            <div class="InputCopy">
                <input
                    ref="input"
                    class="InputCopy__input"
                    type="text"
                    value={value}
                    onFocus={handleFocus}
                    readOnly
                />
                <div class="InputCopy__button-wrapper">
                    <Button class="InputCopy__button" onClick={handleCopy}>
                        {isCopied ? __('copied') : __('copy')}
                    </Button>
                </div>
            </div>
        );
    },
};
