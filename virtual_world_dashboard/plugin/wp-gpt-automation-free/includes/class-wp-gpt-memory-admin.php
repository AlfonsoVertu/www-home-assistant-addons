<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_Memory_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_wp_gpt_update_goal', [__CLASS__, 'ajax_update_goal']);
        add_action('wp_ajax_wp_gpt_delete_goal', [__CLASS__, 'ajax_delete_goal']);
        add_action('wp_ajax_wp_gpt_add_goal', [__CLASS__, 'ajax_add_goal']);
        add_action('wp_ajax_wp_gpt_update_step', [__CLASS__, 'ajax_update_step']);
        add_action('wp_ajax_wp_gpt_delete_step', [__CLASS__, 'ajax_delete_step']);
        add_action('wp_ajax_wp_gpt_add_step', [__CLASS__, 'ajax_add_step']);
    }

    public static function add_menu() {
        if (!WP_GPT_Menu_Visibility::is_page_enabled(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO, 'wp-gpt-memory-manager')) {
            return;
        }

        add_submenu_page(
            'wp-gpt-automation-pro',
            __('Memory Manager','wp-gpt-automation-pro'),
            __('Memory Manager','wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-memory-manager',
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_scripts($hook) {
        if ($hook !== 'wp-gpt-automation-pro_page_wp-gpt-memory-manager') {
            return;
        }
        wp_enqueue_script('wp-gpt-memory-admin', WP_GPT_PLUGIN_URL . 'assets/memory-admin.js', ['jquery'], WP_GPT_VERSION, true);
        wp_localize_script('wp-gpt-memory-admin', 'WpGptMemory', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'restUrl'        => rest_url('wp-gpt/v1/memory/admin'),
            'deleteGoalsUrl' => rest_url('wp-gpt/v1/memory/admin/delete-goals'),
            'deleteStepsUrl' => rest_url('wp-gpt/v1/memory/admin/delete-steps'),
            'restNonce'     => wp_create_nonce('wp_rest'),
            'nonce'         => wp_create_nonce('wp_gpt_memory_admin'),
            'deleteConfirm' => __('Are you sure?','wp-gpt-automation-pro'),
            'editGoalText'  => __('Edit goal title','wp-gpt-automation-pro'),
            'editStepText'  => __('Edit step label','wp-gpt-automation-pro'),
            'noSelection'   => __('Select at least one item to delete.','wp-gpt-automation-pro'),
            'genericError'  => __('Something went wrong. Please try again.','wp-gpt-automation-pro')
        ]);
    }

    public static function render_page() {
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        if (!isset($wp_gpt_memory_manager)) {
            echo '<div class="wrap wp-gpt-admin-page">';
            echo '<div class="wp-gpt-card">';
            echo '<div class="wp-gpt-card-header">';
            echo '<h1>' . esc_html__('Memory Manager','wp-gpt-automation-pro') . '</h1>';
            echo '</div>';
            echo '<div class="wp-gpt-card-body">';
            echo '<p class="wp-gpt-empty">' . esc_html__('Memory manager not available','wp-gpt-automation-pro') . '</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            return;
        }
        $goal_id = isset($_GET['goal_id']) ? intval($_GET['goal_id']) : 0;

        // View steps of a single goal
        if ($goal_id > 0) {
            $goal_res = $wp_gpt_memory_manager->get_goal(['goal_id' => $goal_id, 'user_code' => $user_code]);
            if (!$goal_res['success']) {
                echo '<div class="wrap"><p>' . esc_html__('Goal not found','wp-gpt-automation-pro') . '</p></div>';
                return;
            }
            $goal = $goal_res['goal'];

            $step_per_page = 5;
            $step_page = isset($_GET['step_page']) ? max(1, intval($_GET['step_page'])) : 1;
            $step_off  = ($step_page - 1) * $step_per_page;

            $steps_res = $wp_gpt_memory_manager->list_steps([
                'goal_id' => $goal_id,
                'limit'   => $step_per_page,
                'offset'  => $step_off
            ]);
            $steps = $steps_res['success'] ? $steps_res['steps'] : [];
            $total_steps = $wp_gpt_memory_manager->count_steps(['goal_id' => $goal_id]);
            $steps_pages = $total_steps > 0 ? ceil($total_steps / $step_per_page) : 1;
            ?>
            <div class="wrap wp-gpt-admin-page">
                <div class="wp-gpt-card">
                    <div class="wp-gpt-card-header">
                        <h1><?php echo esc_html($goal['goal_title']); ?></h1>
                        <p><?php esc_html_e('Gestisci i passaggi e monitora lo stato dell\'obiettivo selezionato.', 'wp-gpt-automation-pro'); ?></p>
                    </div>
                    <div class="wp-gpt-card-body">
                        <p><a href="<?php echo esc_url(admin_url('admin.php?page=wp-gpt-memory-manager')); ?>" class="button button-secondary">&laquo; <?php esc_html_e('Back to goals','wp-gpt-automation-pro'); ?></a></p>
                        <?php wp_nonce_field('wp_gpt_memory_admin','wp_gpt_memory_admin_nonce'); ?>
                        <div class="wp-gpt-table-wrapper">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th class="check-column"><input type="checkbox" class="memory-select-all" data-target="step"></th>
                                        <th><?php esc_html_e('ID','wp-gpt-automation-pro'); ?></th>
                                        <th><?php esc_html_e('Label','wp-gpt-automation-pro'); ?></th>
                                        <th><?php esc_html_e('Status','wp-gpt-automation-pro'); ?></th>
                                        <th><?php esc_html_e('Actions','wp-gpt-automation-pro'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($steps as $step): ?>
                                        <tr data-step-id="<?php echo esc_attr($step['id']); ?>" data-goal-id="<?php echo esc_attr($goal_id); ?>">
                                            <th scope="row" class="check-column">
                                                <input type="checkbox" class="memory-select-step" value="<?php echo esc_attr($step['id']); ?>">
                                            </th>
                                            <td><?php echo esc_html($step['id']); ?></td>
                                            <td><?php echo esc_html($step['step_label']); ?></td>
                                            <td><?php echo esc_html($step['status']); ?></td>
                                            <td>
                                                <div class="wp-gpt-flex-row">
                                                    <a href="#" class="button memory-edit-step" data-step-id="<?php echo esc_attr($step['id']); ?>" data-step-label="<?php echo esc_attr($step['step_label']); ?>"><?php esc_html_e('Edit','wp-gpt-automation-pro'); ?></a>
                                                    <a href="#" class="button memory-delete-step" data-step-id="<?php echo esc_attr($step['id']); ?>"><?php esc_html_e('Delete','wp-gpt-automation-pro'); ?></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($steps)) : ?>
                                <p>
                                    <button type="button" class="button button-secondary memory-delete-selected" data-type="step" onclick="return window.wpGptMemoryDeleteSelected && window.wpGptMemoryDeleteSelected(this, event);">
                                        <?php esc_html_e('Delete Selected','wp-gpt-automation-pro'); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        </div>
                        <form id="wp-gpt-add-step-form" method="post" class="wp-gpt-card-form wp-gpt-inline-form">
                            <input type="hidden" name="goal_id" value="<?php echo esc_attr($goal_id); ?>">
                            <input type="text" name="step_label" placeholder="<?php esc_attr_e('New step label','wp-gpt-automation-pro'); ?>" required>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Add Step','wp-gpt-automation-pro'); ?></button>
                        </form>
                        <?php
                            $pagination = paginate_links([
                                'base'   => add_query_arg(['goal_id' => $goal_id, 'step_page' => '%#%']),
                                'format' => '',
                                'current'=> $step_page,
                                'total'  => $steps_pages
                            ]);
                            if ($pagination) {
                                echo '<div class="wp-gpt-pagination">' . $pagination . '</div>';
                            }
                        ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // List of goals
        $per_page = 10;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $res = $wp_gpt_memory_manager->list_goals([
            'limit'  => $per_page,
            'offset' => $offset,
            'user_code' => $user_code
        ]);
        $goals = $res['success'] ? $res['goals'] : [];
        $total_goals = $wp_gpt_memory_manager->count_goals(['user_code' => $user_code]);
        $total_pages = $total_goals > 0 ? ceil($total_goals / $per_page) : 1;
        ?>
        <div class="wrap wp-gpt-admin-page">
            <div class="wp-gpt-card">
                <div class="wp-gpt-card-header">
                    <h1><?php esc_html_e('Memory Manager','wp-gpt-automation-pro'); ?></h1>
                    <p><?php esc_html_e('Organizza gli obiettivi salvati dal tuo GPT e aggiorna rapidamente le informazioni utili.', 'wp-gpt-automation-pro'); ?></p>
                </div>
                <div class="wp-gpt-card-body">
                    <?php wp_nonce_field('wp_gpt_memory_admin','wp_gpt_memory_admin_nonce'); ?>
                    <?php if ($goals) : ?>
                        <div class="wp-gpt-table-wrapper">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th class="check-column"><input type="checkbox" class="memory-select-all" data-target="goal"></th>
                                        <th><?php esc_html_e('Goal','wp-gpt-automation-pro'); ?></th>
                                        <th><?php esc_html_e('Actions','wp-gpt-automation-pro'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($goals as $goal): ?>
                                        <tr data-goal-id="<?php echo esc_attr($goal['id']); ?>" data-step-id="">
                                            <th scope="row" class="check-column">
                                                <input type="checkbox" class="memory-select-goal" value="<?php echo esc_attr($goal['id']); ?>">
                                            </th>
                                            <td>
                                                <a href="<?php echo esc_url(add_query_arg('goal_id', $goal['id'])); ?>">
                                                    <?php echo esc_html($goal['goal_title']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="wp-gpt-flex-row">
                                                    <a href="#" class="button memory-edit-goal" data-goal-id="<?php echo esc_attr($goal['id']); ?>" data-goal-title="<?php echo esc_attr($goal['goal_title']); ?>"><?php esc_html_e('Edit','wp-gpt-automation-pro'); ?></a>
                                                    <a href="#" class="button memory-delete-goal" data-goal-id="<?php echo esc_attr($goal['id']); ?>"><?php esc_html_e('Delete','wp-gpt-automation-pro'); ?></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($goals)) : ?>
                                <p>
                                    <button type="button" class="button button-secondary memory-delete-selected" data-type="goal" onclick="return window.wpGptMemoryDeleteSelected && window.wpGptMemoryDeleteSelected(this, event);">
                                        <?php esc_html_e('Delete Selected','wp-gpt-automation-pro'); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <p class="wp-gpt-empty"><?php esc_html_e('Nessun obiettivo salvato al momento. Crea la tua prima memoria per inizializzare l\'assistente.', 'wp-gpt-automation-pro'); ?></p>
                    <?php endif; ?>
                    <form id="wp-gpt-add-goal-form" method="post" class="wp-gpt-card-form wp-gpt-inline-form">
                        <input type="text" name="goal_title" placeholder="<?php esc_attr_e('New goal title','wp-gpt-automation-pro'); ?>" required>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Add Goal','wp-gpt-automation-pro'); ?></button>
                    </form>
                    <?php
                        $pagination = paginate_links([
                            'base'   => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current'=> $paged,
                            'total'  => $total_pages
                        ]);
                        if ($pagination) {
                            echo '<div class="wp-gpt-pagination">' . $pagination . '</div>';
                        }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function verify_nonce() {
        check_admin_referer('wp_gpt_memory_admin', 'wp_gpt_memory_admin_nonce');
    }

    public static function ajax_update_goal() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $goal_id = intval($_POST['goal_id'] ?? 0);
        $title = sanitize_text_field($_POST['goal_title'] ?? '');
        $result = $wp_gpt_memory_manager->update_goal([
            'goal_id'=>$goal_id,
            'goal_title'=>$title,
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public static function ajax_delete_goal() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $goal_id = intval($_POST['goal_id'] ?? 0);
        $result = $wp_gpt_memory_manager->delete_goal([
            'goal_id'=>$goal_id,
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public static function ajax_add_goal() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $title = sanitize_text_field($_POST['goal_title'] ?? '');
        $result = $wp_gpt_memory_manager->create_goal([
            'goal_title'=>$title,
            'goal_type'=>'default',
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public static function ajax_update_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $step_id = intval($_POST['step_id'] ?? 0);
        $label = sanitize_text_field($_POST['step_label'] ?? '');
        $result = $wp_gpt_memory_manager->update_step([
            'step_id'=>$step_id,
            'step_label'=>$label,
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public static function ajax_delete_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $step_id = intval($_POST['step_id'] ?? 0);
        $result = $wp_gpt_memory_manager->delete_step([
            'step_id'=>$step_id,
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public static function ajax_add_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized','wp-gpt-automation-pro'));
        }
        self::verify_nonce();
        global $wp_gpt_memory_manager;
        $user_code = get_option('wp_gpt_user_code');
        $goal_id = intval($_POST['goal_id'] ?? 0);
        $label = sanitize_text_field($_POST['step_label'] ?? '');
        $result = $wp_gpt_memory_manager->add_step([
            'goal_id'=>$goal_id,
            'step_label'=>$label,
            'user_code'=>$user_code
        ]);
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }
}

WP_GPT_Memory_Admin::init();
