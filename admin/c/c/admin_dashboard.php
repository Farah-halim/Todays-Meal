<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}
require_once 'config.php';

// Fetch statistics
$kitchens_count = $conn->query("SELECT COUNT(*) as count FROM cloud_kitchen_owner")->fetch_assoc()['count'];
$customers_count = $conn->query("SELECT COUNT(*) as count FROM customer")->fetch_assoc()['count'];
$delivery_count = $conn->query("SELECT COUNT(*) as count FROM delivery_man")->fetch_assoc()['count'];
$active_kitchens = $conn->query("SELECT COUNT(*) as count FROM cloud_kitchen_owner WHERE status='active'")->fetch_assoc()['count'];

// Fetch recent admin actions
$admin_actions_query = "
    SELECT aa.*, u.u_name as admin_name 
    FROM admin_actions aa 
    LEFT JOIN users u ON aa.admin_id = u.user_id 
    ORDER BY aa.created_at DESC 
    LIMIT 50
";
$admin_actions_result = $conn->query($admin_actions_query);

// Fetch flagged issues
$pending_orders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'")->fetch_assoc()['count'];
$ready_for_pickup = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'ready_for_pickup'")->fetch_assoc()['count'];
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM cloud_kitchen_owner WHERE is_approved = 0")->fetch_assoc()['count'];
$delivery_pending_approvals = $conn->query("SELECT COUNT(*) as count FROM delivery_man WHERE is_approved = 0")->fetch_assoc()['count'];
$total_flagged_issues = $pending_orders + $ready_for_pickup + $pending_approvals + $delivery_pending_approvals;

// Fetch live orders with real data
$live_orders_query = "
    SELECT o.order_id, u.u_name as customer_name, ko.business_name, 
           o.order_status, o.order_date, o.total_price
    FROM orders o
    JOIN customer c ON o.customer_id = c.user_id
    JOIN users u ON c.user_id = u.user_id
    JOIN cloud_kitchen_owner ko ON o.cloud_kitchen_id = ko.user_id
    WHERE o.order_status IN ('pending', 'preparing', 'ready_for_pickup', 'in_transit')
    ORDER BY o.order_date DESC
    LIMIT 15
";
$live_orders_result = $conn->query($live_orders_query);

// Count order statuses
$pending_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'")->fetch_assoc()['count'];
$preparing_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'preparing'")->fetch_assoc()['count'];
$ready_for_pickup_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'ready_for_pickup'")->fetch_assoc()['count'];
$in_transit_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'in_transit'")->fetch_assoc()['count'];
$delivered_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'delivered'")->fetch_assoc()['count'];
$cancelled_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_status = 'cancelled'")->fetch_assoc()['count'];

// Group related statuses for better display
$active_orders_count = $preparing_count + $ready_for_pickup_count + $in_transit_count;

// Monthly order data for performance chart
$monthly_orders_query = "
    SELECT 
        MONTH(order_date) as month,
        MONTHNAME(order_date) as month_name,
        COUNT(*) as order_count,
        SUM(total_price) as revenue
    FROM orders 
    WHERE YEAR(order_date) = YEAR(CURDATE())
    GROUP BY MONTH(order_date), MONTHNAME(order_date)
    ORDER BY MONTH(order_date)
";
$monthly_orders_result = $conn->query($monthly_orders_query);
$monthly_data = [];
$monthly_labels = [];
while ($row = $monthly_orders_result->fetch_assoc()) {
    $monthly_labels[] = $row['month_name'];
    $monthly_data[] = $row['order_count'];
}

// Kitchen status data
$suspended_kitchens = $conn->query("SELECT COUNT(*) as count FROM cloud_kitchen_owner WHERE status='suspended'")->fetch_assoc()['count'];
$blocked_kitchens = $conn->query("SELECT COUNT(*) as count FROM cloud_kitchen_owner WHERE status='blocked'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <style>
        body { 
            background-color: #fff7e5; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #6a4125;
        }

        .navbar {
            /*background: linear-gradient(135deg, #e57e24, #6a4125);*/
            background: #e57e24;
            padding: 1rem 0;
            box-shadow: 0 4px 12px rgba(106, 65, 37, 0.15);
        }

        .stats-card {
            background: linear-gradient(135deg, #3d6f5d, #2c5547);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(61, 111, 93, 0.15);
            transition: all 0.3s ease;
        }

        .order-status-card {
            background-color:rgb(255, 255, 255);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-pending {
            background-color: #ffc107;
            color: #6a4125;
            border: 1px solid #e0a800;
        }

        .status-preparing {
            background-color: #17a2b8;
            color: white;
            border: 1px solid #138496;
        }

        .status-ready {
            background-color: #6610f2;
            color: white;
            border: 1px solid #520dc2;
        }

        .status-transit {
            background-color: #6c757d;
            color: white;
            border: 1px solid #545b62;
        }

        .status-delivered {
            background-color: #28a745;
            color: white;
            border: 1px solid #1e7e34;
        }

        .status-cancelled {
            background-color: #dc3545;
            color: white;
            border: 1px solid #bd2130;
        }

        .alert-section {
            background-color: #fff;
            border-left: 4px solid #e57e24;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 5px;
        }

        .metrics-container {
            background-color:rgb(255, 255, 255);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .action-log {
            max-height: 300px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 10px;
            border-bottom: 1px solid #dab98b;
        }

        .flagged-item {
            background-color: #fff;
            border-left: 4px solid #ff6b6b;
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }

        .flagged-item.warning {
            border-left-color: #ffc107;
        }

        .flagged-item.info {
            border-left-color: #17a2b8;
        }

    </style>
</head>

<body>

    <nav class="navbar navbar-dark mb-4">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-gauge-high me-2"></i>
                Cloud Kitchen Management Dashboard
            </span>
            <div>
                <a href="financial_accounts.php" class="btn btn-outline-light me-2" title="Financial Accounts">
                    <i class="fas fa-coins"></i>
                    <span class="d-none d-md-inline ms-1">Finances</span>
                </a>
                <button class="btn btn-outline-light me-2" data-bs-toggle="modal" data-bs-target="#notificationsModal">
                    <i class="fas fa-bell"></i>
                    <span class="badge bg-danger"><?php echo $total_flagged_issues; ?></span>
                </button>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </div>
    </nav>
   
    <div class="container">
        <!-- Live Orders Section -->
        <div class="row mb-4">
            <div class="col-12">
                <h4 class="mb-3">Live Orders</h4>
                <div class="order-status-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="status-badge status-pending">
                                <i class="fas fa-clock me-1"></i><?php echo $pending_count; ?> Pending
                            </span>
                            <span class="status-badge status-preparing">
                                <i class="fas fa-utensils me-1"></i><?php echo $preparing_count; ?> Preparing
                            </span>
                            <span class="status-badge status-ready">
                                <i class="fas fa-box me-1"></i><?php echo $ready_for_pickup_count; ?> Ready for Pickup
                            </span>
                            <span class="status-badge status-transit">
                                <i class="fas fa-shipping-fast me-1"></i><?php echo $in_transit_count; ?> In Transit
                            </span>
                            <span class="status-badge status-delivered">
                                <i class="fas fa-check-circle me-1"></i><?php echo $delivered_count; ?> Delivered
                            </span>
                            <?php if ($cancelled_count > 0): ?>
                            <span class="status-badge status-cancelled">
                                <i class="fas fa-times-circle me-1"></i><?php echo $cancelled_count; ?> Cancelled
                            </span>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-primary" style="background-color: #3d6f5d;" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Cloud Kitchen</th>
                                    <th>Order Status</th>
                                    <th>Total</th>
                                    <th>Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($live_orders_result->num_rows > 0): ?>
                                    <?php while ($order = $live_orders_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $order['order_id']; ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($order['business_name']); ?></td>
                                            <td>
                                                <?php
                                                $badge_class = '';
                                                switch ($order['order_status']) {
                                                    case 'pending':
                                                        $badge_class = 'bg-warning';
                                                        break;
                                                    case 'preparing':
                                                        $badge_class = 'bg-info';
                                                        break;
                                                    case 'ready_for_pickup':
                                                        $badge_class = 'bg-primary';
                                                        break;
                                                    case 'in_transit':
                                                        $badge_class = 'bg-secondary';
                                                        break;
                                                    case 'delivered':
                                                        $badge_class = 'bg-success';
                                                        break;
                                                    case 'cancelled':
                                                        $badge_class = 'bg-danger';
                                                        break;
                                                    default:
                                                        $badge_class = 'bg-light';
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?></span>
                                            </td>
                                            <td>$<?php echo number_format($order['total_price'], 2); ?></td>
                                            <td><?php echo date('H:i A', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm" style="background-color: #3d6f5d; color: white;" onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No active orders found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metrics Row -->
        <div class="row mb-4">
            <div class="col">
                <div class="metrics-container text-center">
                    <i class="fas fa-utensils fa-2x mb-2" style="color: #3d6f5d;"></i>
                    <h3><?php echo $active_kitchens; ?></h3>
                    <p>Active <br> Cloud Kitchens</p>
                </div>
            </div>
            <div class="col">
                <div class="metrics-container text-center">
                    <i class="fas fa-users fa-2x mb-2" style="color: #e57e24;"></i>
                    <h3><?php echo $customers_count; ?></h3>
                    <p>Total <br> Customers</p>
                </div>
            </div>
            <div class="col">
                <div class="metrics-container text-center">
                    <i class="fas fa-motorcycle fa-2x mb-2" style="color: #6a4125;"></i>
                    <h3><?php echo $delivery_count; ?></h3>
                    <p>Delivery <br>Agents</p>
                </div>
            </div>
            <div class="col">
                <div class="metrics-container text-center">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: #ff6b6b;"></i>
                    <h3><?php echo $total_flagged_issues; ?></h3>
                    <p>Flagged<br> Issues</p>
                </div>
            </div>
            <div class="col">
                <div class="metrics-container text-center">
                    <i class="fas fa-chart-bar fa-2x mb-2" style="color: #2ecc71;"></i>
                    <h3><?php 
                    // Quick revenue calculation
                    $revenue_query = "SELECT SUM(total_payment) as total_revenue FROM payment_details WHERE MONTH(p_date_time) = MONTH(CURDATE())";
                    $revenue_result = $conn->query($revenue_query);
                    $monthly_revenue = $revenue_result->fetch_assoc()['total_revenue'] ?? 0;
                    echo '$' . number_format($monthly_revenue, 0); 
                    ?></h3>
                    <p>Monthly<br> Revenue</p>
                </div>
            </div>
        </div>

        <!-- Alerts Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h4 class="mb-3">Flagged Issues</h4>
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php if ($ready_for_pickup > 0): ?>
                        <div class="flagged-item">
                            <i class="fas fa-truck text-success me-2"></i>
                            <strong><?php echo $ready_for_pickup; ?> orders ready for pickup</strong>
                            <br><small class="text-muted">Orders waiting for pickup</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pending_orders > 0): ?>
                        <div class="flagged-item warning">
                            <i class="fas fa-clock text-warning me-2"></i>
                            <strong><?php echo $pending_orders; ?> pending orders</strong>
                            <br><small class="text-muted">Orders requiring attention</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pending_approvals > 0): ?>
                        <div class="flagged-item info">
                            <i class="fas fa-store text-info me-2"></i>
                            <strong><?php echo $pending_approvals; ?> cloud kitchen registrations</strong>
                            <br><small class="text-muted">Waiting for approval</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($delivery_pending_approvals > 0): ?>
                        <div class="flagged-item info">
                            <i class="fas fa-motorcycle text-info me-2"></i>
                            <strong><?php echo $delivery_pending_approvals; ?> delivery agent registrations</strong>
                            <br><small class="text-muted">Waiting for approval</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($total_flagged_issues == 0): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-check-circle fa-2x mb-2" style="color: #4CAF50;"></i>
                            <p>No flagged issues at the moment</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <h4 class="mb-3">Recent Admin Actions</h4>
                <div class="action-log">
                    <?php if ($admin_actions_result->num_rows > 0): ?>
                        <?php while ($action = $admin_actions_result->fetch_assoc()): ?>
                            <div class="log-entry">
                                <small class="text-muted"><?php echo date('H:i A', strtotime($action['created_at'])); ?></small>
                                <p class="mb-0">
                                    <strong><?php echo htmlspecialchars($action['admin_name'] ?? 'Admin'); ?></strong>
                                    <?php echo htmlspecialchars($action['action_type']); ?>
                                    <?php if ($action['action_target']): ?>
                                        - <?php echo htmlspecialchars($action['action_target']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-history fa-2x mb-2"></i>
                            <p>No recent admin actions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <h4 class="mb-3">Quick Actions</h4>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="admin_cloud_kitchen_dashboard.php" class="btn btn-lg" style="background-color: #3d6f5d; color: white;">
                        <i class="fas fa-store me-2"></i>Manage Cloud Kitchens
                    </a>
                    <a href="customer_dashboard.php" class="btn btn-lg" style="background-color: #3d6f5d; color: white;">
                        <i class="fas fa-users me-2"></i>Manage Customers
                    </a>
                    <a href="manage_delivery.php" class="btn btn-lg" style="background-color: #3d6f5d; color: white;">
                        <i class="fas fa-motorcycle me-2"></i>Delivery Management
                    </a>
                    <a href="manage_categories.php" class="btn btn-lg" style="background-color: #3d6f5d; color: white;">
                        <i class="fas fa-list me-2"></i>Manage Catalog
                    </a>
                    <a href="../../manage_orders.php" class="btn btn-lg" style="background-color: #3d6f5d; color: white;">
                        <i class="fas fa-cart-arrow-down me-2"></i>Manage Orders
                    </a>
                </div>
            </div>
        </div>

        <!-- Chart Row -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Monthly Orders Performance</h5>
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Kitchen Status Distribution</h5>
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Notifications Modal -->
    <div class="modal fade" id="notificationsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Flagged Issues & Notifications</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($ready_for_pickup > 0): ?>
                        <div class="notification-item p-2 border-bottom">
                            <strong>Ready for Pickup</strong>
                            <p class="mb-0"><?php echo $ready_for_pickup; ?> orders ready for pickup</p>
                            <small class="text-muted">Requires immediate attention</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pending_orders > 0): ?>
                        <div class="notification-item p-2 border-bottom">
                            <strong>Pending Orders</strong>
                            <p class="mb-0"><?php echo $pending_orders; ?> orders pending processing</p>
                            <small class="text-muted">May require follow-up</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pending_approvals > 0): ?>
                        <div class="notification-item p-2 border-bottom">
                            <strong>Registration Approvals</strong>
                            <p class="mb-0"><?php echo $pending_approvals; ?> cloud kitchen registrations awaiting approval</p>
                            <small class="text-muted">Business applications pending</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($delivery_pending_approvals > 0): ?>
                        <div class="notification-item p-2 border-bottom">
                            <strong>Delivery Agent Approvals</strong>
                            <p class="mb-0"><?php echo $delivery_pending_approvals; ?> delivery agents awaiting approval</p>
                            <small class="text-muted">Driver applications pending</small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($total_flagged_issues == 0): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-check-circle fa-2x mb-2" style="color: #4CAF50;"></i>
                            <p>All systems running smoothly!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Performance Chart with real data
        new Chart(document.getElementById('performanceChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthly_labels); ?>,
                datasets: [{
                    label: 'Orders',
                    data: <?php echo json_encode($monthly_data); ?>,
                    borderColor: '#3d6f5d',
                    backgroundColor: 'rgba(61, 111, 93, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#3d6f5d',
                    pointHoverBackgroundColor: '#e57e24',
                    pointHoverBorderColor: '#fff',
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Monthly Order Trends (Current Year)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Status Chart with real data
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Suspended', 'Blocked'],
                datasets: [{
                    data: [<?php echo $active_kitchens; ?>, 
                          <?php echo $suspended_kitchens; ?>, 
                          <?php echo $blocked_kitchens; ?>],
                    backgroundColor: ['#3d6f5d', '#e57e24', '#6a4125']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        AOS.init();

        function viewOrderDetails(orderId) {
            // Redirect to order management system filtered by the specific order
            window.location.href = '../../manage_orders.php?order_id=' + orderId + '&filter=order_id';
        }

        // Auto-refresh every 30 seconds for live updates
        setInterval(() => {
            // Only refresh if there are active orders to avoid unnecessary requests
            if (<?php echo $pending_count + $active_orders_count; ?> > 0) {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>