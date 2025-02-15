import './index.scss';
import { toRefs, computed, ref } from '@vue/composition-api';
import config from '@/globals/config';
import { confirm } from '@/utils/alert';
import useI18n from '@/hooks/vue/useI18n';
import apiEvents from '@/stores/api/events';
import DuplicateEvent from '@/modals/DuplicateEvent';
import Dropdown, { getItemClassnames } from '@/components/Dropdown';

// @vue/component
const EventDetailsHeaderActions = (props, { root, emit }) => {
    const __ = useI18n();
    const { event } = toRefs(props);
    const isConfirming = ref(false);
    const isArchiving = ref(false);
    const isDeleting = ref(false);
    const hasMaterials = computed(() => event.value.materials.length > 0);
    const isConfirmable = computed(() => event.value.materials?.length === 0);
    const hasStarted = computed(() => event.value.startDate.isSameOrBefore(new Date(), 'day'));

    const isPrintable = computed(() => (
        event.value.materials &&
        hasMaterials.value &&
        event.value.beneficiaries &&
        event.value.beneficiaries.length > 0
    ));

    const isVisitor = computed(() => (
        root.$store.getters['auth/is']('visitor')
    ));

    const isEditable = computed(() => {
        const { isPast, isConfirmed, isInventoryDone } = event.value;
        return !isPast || !(isInventoryDone || isConfirmed);
    });

    const isRemovable = computed(() => {
        const { isConfirmed, isInventoryDone } = event.value;
        return !(isConfirmed || isInventoryDone);
    });

    const eventSummaryPdfUrl = computed(() => {
        const { id } = event.value || { id: null };
        return `${config.baseUrl}/events/${id}/pdf`;
    });

    const handleToggleConfirm = async () => {
        if (isConfirming.value) {
            return;
        }
        isConfirming.value = true;

        const { id, isConfirmed } = event.value;

        try {
            const data = await apiEvents.setConfirmed(id, !isConfirmed);
            emit('saved', data);
        } catch (error) {
            emit('error', error);
        } finally {
            isConfirming.value = false;
        }
    };

    const handleToggleArchived = async () => {
        if (isArchiving.value) {
            return;
        }
        isArchiving.value = true;

        const { id, isArchived } = event.value;

        try {
            const data = await apiEvents.setArchived(id, !isArchived);
            emit('saved', data);
        } catch (error) {
            emit('error', error);
        } finally {
            isArchiving.value = false;
        }
    };

    const handleDelete = async () => {
        if (isVisitor.value || !isRemovable.value || isDeleting.value) {
            return;
        }

        const { value: isConfirmed } = await confirm({
            title: __('please-confirm'),
            text: __('@event.confirm-delete'),
            confirmButtonText: __('yes-delete'),
            type: 'danger',
        });
        if (!isConfirmed) {
            return;
        }
        isDeleting.value = true;

        const { id } = event.value;

        try {
            await apiEvents.remove(id);
            emit('deleted', id);
        } catch (error) {
            emit('error', error);
        } finally {
            isDeleting.value = false;
        }
    };

    const handleDuplicated = (newEvent) => {
        emit('duplicated', newEvent);
    };

    const askDuplicate = () => {
        root.$modal.show(DuplicateEvent, { event: event.value, onDuplicated: handleDuplicated }, {
            width: 600,
            draggable: true,
            clickToClose: false,
        });
    };

    return () => {
        const {
            id,
            isPast,
            isConfirmed,
            isInventoryDone,
            isArchived,
        } = event.value;

        return (
            <div class="EventDetailsHeaderActions">
                {isPrintable.value && (
                    <a href={eventSummaryPdfUrl.value} class="button outline" target="_blank" rel="noreferrer">
                        <i class="fas fa-print" /> {__('print')}
                    </a>
                )}
                {isEditable.value && (
                    <router-link to={`/events/${id}`} class="button info">
                        <i class="fas fa-edit" /> {__('action-edit')}
                    </router-link>
                )}
                {(isPast || hasStarted.value) && !isArchived && (
                    <router-link to={`/event-return/${id}`} class="button info">
                        <i class="fas fa-tasks" /> {__('return-inventory')}
                    </router-link>
                )}
                <Dropdown variant="actions">
                    <template slot="items">
                        {!isPast && (
                            <button
                                type="button"
                                class={{
                                    ...getItemClassnames(),
                                    info: isConfirmed,
                                    success: !isConfirmed,
                                }}
                                disabled={isConfirmable.value}
                                onClick={handleToggleConfirm}
                            >
                                {(!isConfirming.value && !isConfirmed) && <i class="fas fa-check" />}
                                {(!isConfirming.value && isConfirmed) && <i class="fas fa-hourglass-half" />}
                                {isConfirming.value && <i class="fas fa-circle-notch fa-spin" />}
                                {' '}{isConfirmed ? __('unconfirm-event') : __('confirm-event')}
                            </button>
                        )}
                        {isPast && isInventoryDone && (
                            <button
                                type="button"
                                class={{ ...getItemClassnames(), info: !isArchived }}
                                onClick={handleToggleArchived}
                            >
                                {!isArchiving.value && <i class="fas fa-archive" />}
                                {isArchiving.value && <i class="fas fa-circle-notch fa-spin" />}
                                {' '}{isArchived ? __('unarchive-event') : __('archive-event')}
                            </button>
                        )}
                        <button
                            type="button"
                            class={{ ...getItemClassnames(), warning: true }}
                            onClick={askDuplicate}
                        >
                            <i class="fas fa-copy" /> {__('duplicate-event')}
                        </button>
                        {isRemovable.value && (
                            <button
                                type="button"
                                class={{ ...getItemClassnames(), danger: true }}
                                onClick={handleDelete}
                            >
                                {!isDeleting.value && <i class="fas fa-trash" />}
                                {isDeleting.value && <i class="fas fa-circle-notch fa-spin" />}
                                {' '}{__('delete-event')}
                            </button>
                        )}
                    </template>
                </Dropdown>
            </div>
        );
    };
};

EventDetailsHeaderActions.props = {
    event: { type: Object, required: true },
};

EventDetailsHeaderActions.emits = ['saved', 'deleted', 'duplicated', 'error'];

export default EventDetailsHeaderActions;
