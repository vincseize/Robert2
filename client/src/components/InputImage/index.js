import './index.scss';
import { ALLOWED_IMAGE_TYPES } from '@/globals/constants';
import config from '@/globals/config';
import formatBytes from '@/utils/formatBytes';
import Button from '@/components/Button';
import Loading from '@/components/Loading';
import Progressbar from '@/components/Progressbar';
import emptyImageSrc from './assets/empty-image.png';

// @vue/component
export default {
    name: 'InputImage',
    props: {
        value: {
            type: [String, File],
            default: null,
        },
        uploading: {
            type: [Number, Boolean],
            default: false,
        },
    },
    computed: {
        isEmpty() {
            return this.value == null;
        },

        isUploading() {
            return this.uploading !== false;
        },

        uploadProgress() {
            const progress = this.uploading;
            if (!this.isUploading || typeof progress !== 'number' || progress === 0) {
                return undefined;
            }
            return progress;
        },

        imageSrc() {
            const { value } = this;
            if (value instanceof File) {
                return URL.createObjectURL(value);
            }
            return value ?? emptyImageSrc;
        },
    },
    methods: {
        handleSelectFile() {
            if (this.isUploading) {
                return;
            }
            this.$refs.input.click();
        },

        handleChange(event) {
            event.preventDefault();
            if (this.isUploading) {
                return;
            }

            const files = event.dataTransfer
                ? event.dataTransfer.files
                : event.target.files;

            if (!files || files.length === 0) {
                return;
            }

            const file = files[0];
            const { type, size } = file;
            const { $t: __ } = this;

            if (!ALLOWED_IMAGE_TYPES.includes(type)) {
                this.$toasted.error(__('errors.file-not-a-valid-image'));
                return;
            }

            if (size > config.maxFileUploadSize) {
                const formattedMaxSize = formatBytes(config.maxFileUploadSize);
                this.$toasted.error(__('errors.file-size-exceeded', { max: formattedMaxSize }));
                return;
            }

            this.$emit('change', file);
        },

        handleRemove() {
            if (this.isEmpty || this.isUploading) {
                return;
            }
            this.$emit('change', null);
        },
    },
    render() {
        const {
            $t: __,
            url,
            imageSrc,
            uploadProgress,
            isEmpty,
            isUploading,
            handleSelectFile,
            handleChange,
            handleRemove,
        } = this;

        const className = ['InputImage', {
            'InputImage--empty': !url,
        }];

        return (
            <div class={className}>
                <div class="InputImage__preview">
                    <img src={imageSrc} class="InputImage__preview__image" alt="" />
                </div>
                <input
                    ref="input"
                    type="file"
                    readonly={isUploading}
                    onChange={handleChange}
                    class="InputImage__input"
                    accept="image/*"
                />
                {!isUploading && (
                    <div class="InputImage__actions">
                        <Button
                            type="success"
                            icon={isEmpty ? 'plus' : 'sync'}
                            onClick={handleSelectFile}
                        >
                            {isEmpty ? __('add-a-picture') : __('change-the-picture')}
                        </Button>
                        {!isEmpty && (
                            <Button type="danger" icon="ban" onClick={handleRemove}>
                                {__('remove-the-picture')}
                            </Button>
                        )}
                    </div>
                )}
                {isUploading && <Loading horizontal />}
                {(isUploading && uploadProgress !== undefined) && (
                    <Progressbar percent={uploadProgress} />
                )}
            </div>
        );
    },
};
