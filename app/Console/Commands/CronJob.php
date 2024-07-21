<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\Log;
use App\Models\OrderProduct;
use App\Models\OrderProductUpgrade;
use Illuminate\Support\Facades\Http;

class CronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:cronjob';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Cron Job';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Cron Job Started');

        $this->handleExpiringOrders();
        $this->handleUpcomingOrders();
        $this->handleOrderProductUpgrades();
        $this->checkExtensionsForUpdates();
        $this->cleanOldLogs();

        $this->info('Cron Job Finished');

        return Command::SUCCESS;
    }

    private function handleExpiringOrders()
    {
        $orders = OrderProduct::where('expiry_date', '<', now())->get();
        foreach ($orders as $order) {
            if ($order->price == 0.00) {
                continue;
            }

            if ($order->status == 'paid' && $order->cancellation()->exists()) {
                $this->cancelOrder($order);
                continue;
            }

            if ($order->status == 'paid') {
                $this->suspendOrder($order);
            } elseif (in_array($order->status, ['suspended', 'pending'])) {
                $this->handleSuspendedOrPendingOrder($order);
            }
        }
    }

    private function handleUpcomingOrders()
    {
        $orders = OrderProduct::where('expiry_date', '<', now()->addDays(7))
                              ->where('status', '!=', 'cancelled')
                              ->get();

        $invoiceProcessed = 0;
        foreach ($orders as $order) {
            if (in_array($order->billing_cycle, ['free', 'one-time']) || $order->price == 0.00 || $order->cancellation()->exists()) {
                continue;
            }

            if ($order->getOpenInvoices()->count() > 0) {
                continue;
            }

            $this->createInvoiceForOrder($order);
            $invoiceProcessed++;
        }

        $this->info('Sent Number of Invoices: ' . $invoiceProcessed);
    }

    private function handleOrderProductUpgrades()
    {
        foreach (OrderProductUpgrade::with('orderProduct')->get() as $orderProductUpgrade) {
            if ($orderProductUpgrade->orderProduct->expiry_date < now()) {
                $orderProductUpgrade->delete();
            } else {
                $this->updateOrderProductUpgrade($orderProductUpgrade);
            }
        }
    }

    private function checkExtensionsForUpdates()
    {
        $extensions = \App\Models\Extension::all();
        foreach ($extensions as $extension) {
            if (!$extension->version) {
                continue;
            }

            $url = config('app.marketplace') . 'extensions?version=' . config('app.version') . '&search=' . $extension->name;
            $response = Http::get($url)->json();

            if (isset($response['error']) || count($response['data']) == 0) {
                continue;
            }

            $latestVersion = $response['data'][0]['versions'][0]['version'];
            if (version_compare($extension->version, $latestVersion, '<')) {
                $extension->update_available = $latestVersion;
                $extension->save();
                $this->info('Update available for ' . $extension->name . ' to version ' . $latestVersion);
            }
        }
    }

    private function cleanOldLogs()
    {
        $deletedLogsCount = Log::where('created_at', '<', now()->subDays(7))->delete();
        $this->info('Deleted Logs: ' . $deletedLogsCount);
    }

    private function cancelOrder($order)
    {
        $cancellation = $order->cancellation;
        $order->status = 'cancelled';
        $order->save();

        ExtensionHelper::terminateServer($order);
        NotificationHelper::sendDeletedOrderNotification($order->order, $order->order->user, $cancellation);
    }

    private function suspendOrder($order)
    {
        $order->status = 'suspended';
        $order->save();

        ExtensionHelper::suspendServer($order);

        $invoice = $order->getOpenInvoices()->first();
        if ($invoice) {
            NotificationHelper::sendUnpaidInvoiceNotification($invoice, $order->order->user);
        }

        $this->info('Suspended server: ' . $order->id);
    }

    private function handleSuspendedOrPendingOrder($order)
    {
        if (strtotime($order->expiry_date) < strtotime('-' . config('settings::remove_unpaid_order_after', 7) . ' days')) {
            ExtensionHelper::terminateServer($order);
            $order->status = 'cancelled';
            $order->save();

            NotificationHelper::sendDeletedOrderNotification($order->order, $order->order->user);

            $invoice = $order->getOpenInvoices()->first();
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->status = 'cancelled';
                $invoice->cancelled_at = now()->format('Y-m-d H:i:s');
                $invoice->save();
                $this->info('Invoice ' . $invoice->id . ' status changed to ' . $invoice->status);
            }
        }
    }

    private function createInvoiceForOrder($order)
    {
        $invoice = new Invoice();
        $invoice->order_id = $order->order->id;
        $invoice->status = 'pending';
        $invoice->user_id = $order->order->user_id;
        $invoice->saveQuietly();

        $date = $this->calculateNextBillingDate($order->expiry_date, $order->billing_cycle);
        
        $invoiceItem = new \App\Models\InvoiceItem();
        $invoiceItem->invoice_id = $invoice->id;
        $invoiceItem->product_id = $order->id;
        $description = $order->product()->get()->first() ? $order->product()->get()->first()->name . ' (' . $order->expiry_date . ' - ' . $date . ')' : '';
        $invoiceItem->description = $description;
        $invoiceItem->total = $order->price;
        $invoiceItem->save();

        NotificationHelper::sendNewInvoiceNotification($invoice, $order->order->user);

        event(new \App\Events\Invoice\InvoiceCreated($invoice));

        if ($invoice->total() == 0) {
            ExtensionHelper::paymentDone($invoice->id);
            $this->info('Invoice ' . $invoice->id . ' status changed to ' . $invoice->status);
        }

        $this->info('Sent Invoice: ' . $invoice->id);
    }

    private function updateOrderProductUpgrade($orderProductUpgrade)
    {
        $invoiceItem = $orderProductUpgrade->invoice->items->first();
        $invoiceItem->total = $this->calculateAmount($orderProductUpgrade->product, $orderProductUpgrade->orderProduct);
        $invoiceItem->save();

        $this->info('Updated Invoice Item: ' . $invoiceItem->id);
    }

    private function calculateNextBillingDate($expiry_date, $billing_cycle)
    {
        switch ($billing_cycle) {
            case 'monthly':
                return date('Y-m-d', strtotime('+1 month', strtotime($expiry_date)));
            case 'quarterly':
                return date('Y-m-d', strtotime('+3 months', strtotime($expiry_date)));
            case 'semi_annually':
                return date('Y-m-d', strtotime('+6 months', strtotime($expiry_date)));
            case 'annually':
                return date('Y-m-d', strtotime('+1 year', strtotime($expiry_date)));
            case 'biennially':
                return date('Y-m-d', strtotime('+2 years', strtotime($expiry_date)));
            case 'triennially':
                return date('Y-m-d', strtotime('+3 years', strtotime($expiry_date)));
            default:
                return date('Y-m-d', strtotime('+1 month', strtotime($expiry_date)));
        }
    }

    private function calculateAmount($product, $orderProduct)
    {
        $cycleToDays = [
            'monthly' => 30,
            'quarterly' => 90,
            'semi-annually' => 180,
            'annually' => 365,
            'biennially' => 730,
            'triennially' => 1095,
        ];

        $amount = $product->price($orderProduct->billing_cycle) - ($orderProduct->product->price($orderProduct->billing_cycle) / $cycleToDays[$orderProduct->billing_cycle] * $orderProduct->expiry_date->diffInDays());

        return $amount;
    }
}
