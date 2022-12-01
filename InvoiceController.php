<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Link;
use App\Models\User;
use App\Models\Setting;
use App\Models\Bank;
use App\Models\InvoicesPdf;
use App\Models\InvoiceDistribution;
// use App\Models\InvoicePaidAlert;
use Auth;
use App\Imports\InvoiceImport;
use App\Exports\InvoiceExport;
use App\Exports\BatchExport;
use App\Exports\ExportTrackInvoice;
use App\Exports\ExportAmountPayable;
use App\Exports\ExportAllInvoices;
use App\Exports\ExportAmountRecievable;
use Maatwebsite\Excel\Facades\Excel;
use Smalot\PdfParser\Parser;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class InvoiceController extends Controller
{
    public function ExportTrackInvoices(Request $request) // export track invoice
    {
        $deleteInvoices = $request->DeleteInvoices;
        $exportInvoices = $request->ExportInvoices;

        $invoice_ids = $request->invoice_id;
        if ($request->invoiceIds) {
            $invoice_ids = $request->invoiceIds;
        }
        if (!isset($invoice_ids)) {
            return redirect()->back()->with("error","Please select at least one invoice to update.");
        }

        if (isset($deleteInvoices) && $deleteInvoices == 'Yes') {
            $supplier = User::join('invoices', 'users.id', '=', 'invoices.supplier_id')
                            ->whereIn('invoices.id', $invoice_ids)
                            ->where(function ($query) {
                                $query->orWhere('payment_status', 'Paid')
                                      ->orWhere('supplier_approval', 'Approved')
                                      ->orWhere('is_supplier_approval', 0);
                            })
                            ->first();

            if ($supplier) {
                return redirect()->back()->with("error", "Invoice(s) cannot be deleted.");
            }

            foreach($invoice_ids as $invoice_id) {
                $invoice = Invoice::where('id', $invoice_id)->first();

                if ($invoice->payment_status == "Paid" || $invoice->supplier_approval == "Approved" || !$invoice->supplier->is_supplier_approval) {
                    //
                } else {
                    $Invoice->delete();
                }
            }

            return redirect()->back()->with("status", "Invoices deleted successfully.");
        }

        if (isset($exportInvoices) && $exportInvoices == 'Yes') {
            $ids = implode(',', $invoice_ids);
            return Excel::download(new ExportTrackInvoice($ids), 'invoices.xlsx');
        }
    }

    public function ExportAmountPayable(Request $request) // export track invoice
    {
        $invoice_ids = $request->invoice_id;
        if (!isset($invoice_ids)) {
            return redirect()->back()->with("error","Please select at least one invoice to export.");
        }

        $ids = implode(',', $invoice_ids);
        return Excel::download(new ExportAmountPayable($ids), 'amount.xlsx');
    }

    public function ExportAmountRecievable(Request $request) // Export Amount Receivable Invoices
    {
        $deleteAll   = $request->DeleteAll;
        $exportAll   = $request->ExportAll;
        $receiveAll  = $request->receiveAll;
        $repurchasedAll = $request->repurchasedAll;
        $invoice_ids = $request->invoice_id;
        $batchRefs   = $request->batch;

        if (!isset($invoice_ids) && !$batchRefs) {
            return redirect()->back()->with("error","Please select at least one invoice to export/delete.");
        }

        if ($batchRefs) {
            $invoice_ids = Invoice::whereIn('batch_ref', $batchRefs)
                                  ->where('payment_status', 'Paid')
                                  ->where('due_status', '!=', 'Paid')
                                  ->pluck('id')->toArray();
        }

        if (isset($deleteAll) && $deleteAll == 'Yes') {
            foreach($invoice_ids as $invoice_id) {
                $Invoice = Invoice::find($invoice_id)->delete();
            }

            return redirect()->back()->with("status", "Invoices deleted successfully.");
        }

        if (isset($repurchasedAll) && $repurchasedAll == 'Yes') {
            foreach ($invoice_ids as $invoice_id) {
                $Invoice = InvoiceDistribution::where('invoice_id', $invoice_id);
                $Invoice->update([
                    'is_repurchased' => 1,
                ]);
            }

            return redirect()->back()->with("status", "Invoices repurchased successfully.");
        }

        if (isset($exportAll) && $exportAll == 'Yes') {
            $ids = implode(',', $invoice_ids);
            return Excel::download(new ExportAmountRecievable($ids), 'amount.xlsx');
        }

        if (isset($receiveAll) && $receiveAll == 'Yes') {
            foreach($invoice_ids as $invoiceId) {
                $invoice = Invoice::where('id', $invoiceId)
                                  ->where('payment_status', 'Paid')
                                  ->where('due_status', 'Pending')
                                  ->first();

                if ($invoice) {
                    $invoice->update([
                        'due_status' => 'Paid',
                        'old_disbursement_date'       => date('Y-m-d'),
                        // 'estimated_disbursement_date' => date('Y-m-d')
                    ]);
                }
            }

            return redirect()->back()->with("status", "Invoices Paid successfully.");
        }
    }

    public function AdminInvoices(Request $request) // load invoices in admin
    {
        $invoices  = Invoice::where('status', '!=', 'Uploaded')->latest()
                            ->paginate(200);
                            // ->get();
        $suppliers = User::where('role', 'supplier')->latest()->get();
        $buyers    = User::where('role', 'buyer')->latest()->get();
        $investors = User::where('role', 'investor')->latest()->get();
        $batchIds  = Invoice::whereNotNull('batch_id')->where('buyer_approval_batch', 'Pending')->pluck('batch_id')->toArray();
        $batchIds  = array_unique($batchIds);
        if ($request->ajax()) {
            $count = $request->count;
            $pageName = $request->pageName;
            $res = $this->htmlAjax($invoices, $count, $pageName);
            return $res;
        }

        return view('dashboards.admins.invoices.index', compact('invoices', 'suppliers', 'buyers', 'investors', 'batchIds'));
    }

    public function approveInvoice(Request $request) // Approve Invoices By Admin
    {
        $ids = $request->invoiceIds;
        $ids = $ids[0];
        if (!$ids) {
            return redirect()->back()->with("error", "Please select at least one invoice to distribute.");
        }

        $invoiceIds = explode(',', $ids);

        $invoices = Invoice::whereIn('id', $invoiceIds)
                          ->where('supplier_approval', 'Approved')
                          ->whereNULL('batch_id')
                          ->get();

        $batchInvoices = $invoices->groupBy('batch_ref');
        foreach ($batchInvoices as $key => $batchInvoice) {
            $last_invoice = Invoice::orderBy('batch_approval_no', 'desc')
                                  ->where('batch_ref', $key)
                                  ->whereNotNull('batch_approval_no')
                                  ->first();
            foreach ($batchInvoice as $invoice) {
                if (!$request->batch_options) {
                    if (isset($last_invoice)) {
                        $counter            = 1;
                        $last_invoice_batch = $last_invoice->batch_approval_no;
                        $counter            += $last_invoice_batch;
                        $batch_id           = $invoice->batch_ref.'.'.$counter;
                        $batchNo            = $counter;
                    } else {
                        $counter   = 1;
                        $batch_id  = $invoice->batch_ref.'.'.$counter;
                        $batchNo   = $counter;
                    }

                    $invoice->update([
                        'batch_id'             => $batch_id,
                        'batch_approval_no'    => $batchNo,
                        'admin_approval_batch' => 'Approved'
                    ]);
                }

                if ($request->batch_options) {
                    $batchInvoice = Invoice::where('batch_id', $request->batch_options)->first();
                    $invoice->update([
                        'batch_id'             => $request->batch_options,
                        'batch_approval_no'    => $batchInvoice->batch_approval_no,
                        'admin_approval_batch' => 'Approved'
                    ]);
                }
            }
        }

        $groupInvoices = $invoices->groupBy('supplier_id');
        foreach ($groupInvoices as $supplierKey => $supplierGroups) {
            // foreach ($supplierGroups as $dateKey => $groupDueDates) {
                foreach ($supplierGroups as $key => $invoice) {
                    $appliedTenor = 0;
                    if (isset($invoice->tenor_days)) {
                        $appliedTenor = $invoice->tenor_days;
                    } else {
                        $Link = Link::where('supplier_id', $invoice->supplier_id)->where('buyer_id', $invoice->buyer_id)->where('created_at', '<=', $invoice->created_at)->latest()->first();
                        $payment_type = '';
                        if (isset($Link)) {
                            $payment_type = $Link->payment_type;
                            if ($payment_type == "Monthly") {
                                $payment_term = $invoice->payment_terms_days;
                                if ($payment_term <= 30) {
                                    $appliedTenor = 30;
                                } elseif ($payment_term >= 31 && $payment_term <= 60) {
                                    $appliedTenor = 60;
                                } elseif ($payment_term >= 61 && $payment_term <= 90) {
                                    $appliedTenor = 90;
                                } else {
                                    $appliedTenor = 90;
                                }
                            } elseif ($payment_type == "Daily") {
                                $payment_term = $invoice->payment_terms_days;
                                if ($payment_term > 90) {
                                    $appliedTenor = 90;
                                } else {
                                    $appliedTenor = $payment_term;
                                }
                            }
                        }

                        if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
                            $appliedTenor = $Link->fixed_tenor;
                        }
                    }

                    $groupLastInvoices = Invoice::where('supplier_id', $invoice->supplier_id)
                                              // ->where('due_date', $invoice->due_date)
                                              ->where('batch_id', $invoice->batch_id)
                                              ->whereNotNull('group_id')
                                              // ->orderBy('group_no', 'desc')
                                              ->get();

                    $supplierCode = $invoice->supplier->role_id;
                    $supplierCode = str_replace('0', '', $supplierCode);
                    if (count($groupLastInvoices) > 0) {
                        foreach ($groupLastInvoices as $groupLastInvoice) {
                            $groupTenor = 0;
                            if (isset($groupLastInvoice->tenor_days)) {
                                $groupTenor = $groupLastInvoice->tenor_days;
                            } else {
                                $groupLink = Link::where('supplier_id', $groupLastInvoice->supplier_id)->where('buyer_id', $groupLastInvoice->buyer_id)->where('created_at', '<=', $groupLastInvoice->created_at)->latest()->first();
                                $groupTenor = $this->appliedTenor($groupLastInvoice, $groupLink);

                                if ($groupLink && $groupLink->fixed_tenor && $groupLink->payment_type == "Fixed") {
                                    $groupTenor = $groupLink->fixed_tenor;
                                }
                            }

                            if ($invoice->supplier_id == $groupLastInvoice->supplier_id && $groupTenor == $appliedTenor
                                // && $invoice->due_date == $groupLastInvoice->due_date
                            ) {
                                $group_id = $groupLastInvoice->group_id;
                                $groupNo  = $groupLastInvoice->group_no;
                            } else {
                                $counter            = 1;
                                $groupLastInvoice_batch = $groupLastInvoice->group_no;
                                $counter            += $groupLastInvoice_batch;
                                $group_id           = $invoice->batch_id.'.'.$supplierCode.'.'.$counter;
                                $groupNo            = $counter;
                            }

                            $invoice->update([
                              'group_id' => $group_id,
                              'group_no' => $groupNo
                            ]);
                        }
                    } else {
                        $counter   = 1;
                        $group_id  = $invoice->batch_id.'.'.$supplierCode.'.'.$counter;
                        $groupNo   = $counter;

                        $invoice->update([
                          'group_id' => $group_id,
                          'group_no' => $groupNo
                        ]);
                    }
                }
            // }
        }

        return redirect()->back()->with("success", "Successfully accepted invoice(s).");
    }

    public function invoicesToPay(Request $request) // Showing Invoice to pay data
    {
        $invoices  = Invoice::where('status', '!=', 'Uploaded')
                            ->where('payment_status', 'In Process')
                            ->where('supplier_approval', 'Approved')
                            ->latest()
                            // ->get();
                            ->paginate(200);
        $suppliers = User::where('role', 'supplier')->latest()->get();
        $buyers    = User::where('role', 'buyer')->latest()->get();
        $investors = User::where('role', 'investor')->latest()->get();
        $batchIds  = Invoice::whereNotNull('batch_id')->where('buyer_approval_batch', 'Pending')->pluck('batch_id')->toArray();
        $batchIds  = array_unique($batchIds);
        if ($request->ajax()) {
            $count = $request->count;
            $pageName = $request->pageName;
            $res = $this->htmlAjax($invoices, $count, $pageName);
            return $res;
        }

        return view('dashboards.admins.invoices.invoice-to-pay', compact('invoices', 'suppliers', 'buyers', 'investors', 'batchIds'));
    }

    public function FilterInvoices(Request $request)// filter invoices in admin
    {
        $from           = $request->from;
        $to             = $request->to;
        $supplier       = $request->supplier;
        $buyer          = $request->buyer;
        $payment_status = $request->payment_status;
        $batch_refrence = $request->batch_refrence;

        // from
        if (isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->latest()->get();
        }

        // to
        if (!isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->latest()->get();
        }

        // supplier
        if (!isset($from) && !isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->latest()->get();
        }

        // buyer
        if (!isset($from) && !isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }

        // payment_status
        if (!isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }

        // batch_reference
        if (!isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }




        // from & to
        if (isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->latest()->get();
        }

        // from & supplier
        if (isset($from) && !isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('supplier_id', $supplier)
                                ->latest()->get();
        }

        // from & buyer
        if (isset($from) && !isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }

        // from & payment_status
        if (isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }

        // from & batch_reference
        if (isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }



        // to & supplier
        if (!isset($from) && isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->latest()->get();
        }

        // to & buyer
        if (!isset($from) && isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }

        // to & payment_status
        if (!isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }

        // to & batch_reference
        if (!isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }




        // supplier & buyer
        if (!isset($from) && !isset($to) && isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }
        // supplier & payment_status
        if (!isset($from) && !isset($to) && isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // supplier & batch_reference
        if (!isset($from) && !isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }


        // buyer & payment_status
        if (!isset($from) && !isset($to) && !isset($supplier) && isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('buyer_id',  $buyer)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }

        // buyer & batch_reference
        if (!isset($from) && !isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('buyer_id',  $buyer)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }



        // payment_status & batch_reference
        if (!isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('payment_status', $payment_status)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }



        // from, to & supplier
        if (isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->latest()->get();
        }
        // from, to & buyer
        if (isset($from) && isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }
        // from, to & payment_status
        if (isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // from, to & batch_reference
        if (isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }


        // to, supplier & buyer
        if (!isset($from) && isset($to) && isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }
        // to, supplier & payment_status
        if (!isset($from) && isset($to) && isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // to, supplier & batch_reference
        if (!isset($from) && isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }


        // supplier, buyer & payment_status
        if (!isset($from) && !isset($to) && isset($supplier) && isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->where('buyer_id',  $buyer)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // supplier, buyer & batch_reference
        if (!isset($from) && !isset($to) && isset($supplier) && isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('supplier_id', $supplier)
                                ->where('buyer_id',  $buyer)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }

        // buyer, payment_status & batch_reference
        if (!isset($from) && !isset($to) && !isset($supplier) && isset($buyer) && isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('buyer_id',  $buyer)
                                ->where('payment_status', $payment_status)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }

        // from, to, supplier & buyer
        if (isset($from) && isset($to) && isset($supplier) && isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('buyer_id',  $buyer)
                                ->latest()->get();
        }
        // from, to, supplier & payment_status
        if (isset($from) && isset($to) && isset($supplier) && !isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // from, to, supplier & batch_reference
        if (isset($from) && isset($to) && isset($supplier) && !isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('supplier_id', $supplier)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }

        // from, to, buyer & payment_status
        if (isset($from) && isset($to) && !isset($supplier) && isset($buyer) && isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('buyer_id',  $buyer)
                                ->where('payment_status', $payment_status)
                                ->latest()->get();
        }
        // from, to, buyer & batch_reference
        if (isset($from) && isset($to) && !isset($supplier) && isset($buyer) && !isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('buyer_id',  $buyer)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }

        // from, to, payment_status & batch_reference
        if (isset($from) && isset($to) && !isset($supplier) && !isset($buyer) && isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->where('issue_date', '>=', $from)
                                ->where('issue_date', '<=', $to)
                                ->where('payment_status', $payment_status)
                                ->where('batch_ref', $batch_refrence)
                                ->latest()->get();
        }

        // all
        if (isset($from) && isset($to) && isset($supplier) && isset($buyer) && isset($payment_status) && isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->latest()->get();
        }

        // none
        if (!isset($from) && !isset($to) && !isset($supplier) && !isset($buyer) && !isset($payment_status) && !isset($batch_refrence)) {
            $invoices = Invoice::where('status', '!=', 'Uploaded')
                                ->latest()->get();
        }


        $suppliers = User::where('role', 'supplier')->latest()->get();
        $buyers = User::where('role', 'buyer')->latest()->get();

        return view('dashboards.admins.invoices.index', compact('invoices', 'suppliers', 'buyers'));
    }

    public function ImportInvoice(Request $request) // import invoice
    {
        $validatedData = $request->validate([
            // 'supplier_id'   => 'required',
            'file'          => 'required|file',
        ]);
        $supplier_id = $request->supplier_id ?? '';

        $get_last_record_in_this_month = Invoice::whereMonth('dated', date('m'))
                                                ->whereYear('dated', date('Y'))
                                                ->whereDay('dated', date('d'))
                                                ->orderBy('id', 'desc')->count();
        $counter = 1;
        if ($get_last_record_in_this_month > 0):
            $last_invoice = Invoice::whereMonth('dated', date('m'))
                                  ->whereYear('dated', date('Y'))
                                  ->whereDay('dated', date('d'))
                                  ->orderBy('id', 'desc')->first();
            $last_invoice_batch = $last_invoice->batch;

            $counter   += $last_invoice_batch;
            // $batch_ref = date('M-y-').$counter;
            $batch_ref = date('ymd.').$counter;
        else:
            // $batch_ref = date('M-y-').$counter;
            $batch_ref = date('ymd.').$counter;
        endif;
        $batch     = $counter;

        // $file = $request->file('file');
        // $requiredHeaders = array('issue_date', 'due_date', 'amount', 'invoice_number'); //headers we expect
        // $f = fopen($file, 'r');
        // $firstLine = fgets($f); //get first line of csv file
        // fclose($f); // close file
        // $foundHeaders = str_getcsv(trim($firstLine), ',', '"'); //parse to array

        // $result=array_diff($requiredHeaders,$foundHeaders);

        // if (count($result) > 0) {
        //     return redirect()->back()->with("error", 'Headers do not match: '.implode(', ', $foundHeaders));
        // }
        $duplicate = $request->duplicate;

        Excel::import(new InvoiceImport($supplier_id, $batch_ref, $batch, $duplicate),request()->file('file'));

        // $currentInvoices = Invoice::where('batch_ref', $batch_ref)->get();
        $currentInvoices = Invoice::where('status', 'Uploaded')->where('buyer_id', Auth::user()->id)->latest()->get();
        $totalAmount = 0;
        foreach ($currentInvoices as $key => $currentInvoice) {
            $totalAmount = $totalAmount + $currentInvoice->amount;
        }
        // $invoiceAmounts = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->pluck('amount')->toArray();
        $invoiceAmounts = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id)->latest()->pluck('amount')->toArray();
        $invoiceTotalAmount = 0;
        foreach ($invoiceAmounts as $invoiceAmount) {
            $invoiceTotalAmount = $invoiceTotalAmount + $invoiceAmount;
        }
        $amount = $totalAmount + $invoiceTotalAmount;

        $buyerLimit = Setting::where('user_id', Auth::id())->first();

        if (isset($buyerLimit) && $buyerLimit->buyer_limit) {
            if ($buyerLimit->buyer_limit < $amount) {
                if ($buyerLimit->buyer_limit <= $invoiceTotalAmount) {
                    Invoice::where('batch_ref', $batch_ref)->delete();
                    if ($request->ajax()) {
                        return response()->json(["error" => "Check CSV file – Your limit is full."]);
                    }
                    return redirect()->back()->with("error","Check CSV file – Your limit is full.");
                }
                $leftAmount = $buyerLimit->buyer_limit - $invoiceTotalAmount;
                Invoice::where('batch_ref', $batch_ref)->delete();
                $currentInvoices = Invoice::where('status', 'Uploaded')->where('buyer_id', Auth::user()->id)->latest()->get();
                $totalAmount = 0;
                foreach ($currentInvoices as $key => $currentInvoice) {
                    $totalAmount = $totalAmount + $currentInvoice->amount;
                }
                $leftAmount = $leftAmount - $totalAmount;
                if ($request->ajax()) {
                    return response()->json(["error" => "Check CSV file – You have only $leftAmount limit left."]);
                }
                return redirect()->back()->with("error","Check CSV file – You have only $leftAmount limit left.");
            }
        }

        if ($request->ajax()) {
            return response()->json(["success" => "Successfully imported invoice."]);
        }
        return redirect()->back()->with("success","Successfully imported invoice.");
    }

    public function InvoicePayRejectExport(Request $request) // Export, Pay, Reject multiple
    {
        $ExportAll  = $request->ExportAll;
        $PayAll     = $request->PayAll;
        $RejectAll  = $request->RejectAll;
        $DeleteAll  = $request->DeleteAll;
        $receiveAll = $request->receiveAll;
        $notReceiveAll = $request->notReceiveAll;
        $repurchasedAll = $request->repurchasedAll;
        $invoiceToPay  = $request->invoiceToPay;

        $invoice_ids = $request->invoice_id;
        if ($request->invoiceIds) {
            $invoice_ids = $request->invoiceIds;
        }
        $batchRefs = $request->batch;
        if (!isset($invoice_ids) && !$batchRefs) {
            return redirect()->back()->with("error","Please select at least one invoice to update.");
        }

        if ($batchRefs) {
            $invoice_ids = Invoice::whereIn('batch_ref', $batchRefs)->where('status', '!=', 'Uploaded');
            if (isset($invoiceToPay) && $invoiceToPay == 'invoiceToPay') {
                $invoice_ids = $invoice_ids->where('payment_status', 'In Process')
                                           ->where('supplier_approval', 'Approved');
            }
            $invoice_ids = $invoice_ids->pluck('id')->toArray();
        }

        if (isset($ExportAll) && $ExportAll == 'Yes') {
            $type = '';
            $invoiceFileName = 'invoices.xlsx';
            if (isset($invoiceToPay) && $invoiceToPay == 'invoiceToPay') {
                $type = 'invoiceToPay';
                $invoiceFileName = 'invoices-to-pay.xlsx';
            }
            if ($request->ExportAllInvoice == 'Yes') {
                if ($request->allIdsStr) {
                    $invoice_ids = $request->allIdsStr;
                    $invoice_ids = explode(',', $invoice_ids);
                } else {
                    $invoice_ids = Invoice::where('status', '!=', 'Uploaded');
                    if (isset($invoiceToPay) && $invoiceToPay == 'invoiceToPay') {
                        $invoice_ids = $invoice_ids->where('payment_status', 'In Process')
                                  ->where('supplier_approval', 'Approved');
                    }
                    $invoice_ids = $invoice_ids->pluck('id')->toArray();
                }
            }
            $ids = implode(',', $invoice_ids);
            return Excel::download(new InvoiceExport($ids, $type), $invoiceFileName);
        }

        if (isset($PayAll) && $PayAll == 'Yes') {
            $invoice = Invoice::whereIn('id', $invoice_ids)->where('buyer_approval_batch', 'Pending')->get();
            if (count($invoice) >= 1) {
                return redirect()->back()->with("error", "Invoices are not approved by buyer.");
            }

            $count = 1;
            foreach ($invoice_ids as $invoice_id) {
                $Invoice = Invoice::find($invoice_id);
                $Invoice->payment_status = "Paid";
                $Invoice->estimated_disbursement_date = date('Y-m-d');

                // if (!isset($Invoice->invoice_ref)) {
                //     $get_last_record = Invoice::orderBy('invoice_ref', 'desc')->whereNotNull('invoice_ref')->first();
                //     if (isset($get_last_record)) {
                //         $ref = $get_last_record->invoice_ref;
                //         $Invoice->invoice_ref = $ref+1;
                //     } else {
                //         $Invoice->invoice_ref = 1;
                //     }
                // }
                $Invoice->save();
                $count++;
            }

            $ids = implode(',', $invoice_ids);
            \Session::put('batch_payment_ids', $ids);
            return redirect()->back()->with("success", "Successfully updated invoice(s).");
        }

        if (isset($repurchasedAll) && $repurchasedAll == 'Yes') {
            foreach ($invoice_ids as $invoice_id) {
                $Invoice = InvoiceDistribution::where('invoice_id', $invoice_id);
                $Invoice->update([
                    'is_repurchased' => 1,
                ]);
            }
        }

        if (isset($RejectAll) && $RejectAll == 'Yes') {
            foreach ($invoice_ids as $invoice_id) {
                $Invoice = Invoice::find($invoice_id);
                $Invoice->payment_status = "Rejected";
                $Invoice->save();
            }
        }

        if (isset($DeleteAll) && $DeleteAll == 'Yes') {
            foreach ($invoice_ids as $invoice_id) {
                $Invoice = Invoice::find($invoice_id)->delete();
            }
        }

        if (isset($receiveAll) && $receiveAll == 'Yes') {
            foreach ($invoice_ids as $invoiceId) {
                $invoice = Invoice::where('id', $invoiceId)
                                  ->where('payment_status', 'Paid')
                                  ->where('due_status', 'Pending')
                                  ->first();

                if ($invoice) {
                    $invoice->update([
                        'due_status' => 'Paid',
                        'old_disbursement_date'       => date('Y-m-d'),
                        // 'estimated_disbursement_date' => date('Y-m-d')
                    ]);
                }
            }
        }

        if (isset($notReceiveAll) && $notReceiveAll == 'Yes') {
            foreach($invoice_ids as $invoiceId) {
                $invoice = Invoice::where('id', $invoiceId)
                                  ->where('payment_status', 'Paid')
                                  ->where('due_status', 'Paid')
                                  ->first();

                if ($invoice) {
                    $invoice->update([
                        'due_status' => 'Pending'
                    ]);
                }
            }
        }

        return redirect()->back()->with("success","Successfully updated invoice(s).");
    }

    public function batchPayment()
    {
        $invoice_ids = \Session::get('batch_payment_ids');
        $invoice_ids = explode(',', $invoice_ids);
        \Session::put('batch_payment_ids', '');

        if ($invoice_ids[0] != '') {
            return Excel::download(new BatchExport($invoice_ids), 'Batch Payments.csv');
        }
    }

    public function destroy($id) // delete invoice
    {
        $Invoice = Invoice::find($id);
        if ($Invoice->delete()) {
            return redirect()->back()->with("success","Invoice deleted successfully.");
        }else{
            return redirect()->back()->with("error","Failed to delete Invoice.")->withInput();
        }
    }

    public function deleteMultipleInvoice(Request $request) // delete multiple invoices
    {
        $confirm = $request->confirm;
        $invoice_ids = $request->invoice_id;

        $invoice_ids = explode(',', $request->invoice_id);
        // if (!isset($invoice_ids)) {
        //     return redirect()->back()->with("error","Please select at least one invoice to upload.");
        // }

        if (isset($confirm) && $confirm == 'Yes'):
            $last_invoice = Invoice::whereMonth('dated', date('m'))
                                  ->whereYear('dated', date('Y'))
                                  ->whereDay('dated', date('d'))
                                  ->where('status', 'Pending')
                                  ->orderBy('id', 'desc')->first();

            $get_last_record = Invoice::orderBy('invoice_ref', 'desc')->whereNotNull('invoice_ref')->first();

            $ref = 0;
            if (isset($get_last_record)) {
              $ref = $get_last_record->invoice_ref;
            }
            foreach($invoice_ids as $invoice_id):
                if ($invoice_id != '') {
                    if (isset($last_invoice)):
                        $counter = 1;
                        $last_invoice_batch = $last_invoice->batch;
                        $counter   += $last_invoice_batch;
                        // $batch_ref = date('M-y-').$counter;
                        $batch_ref = date('ymd.').$counter;
                        $batch     = $counter;
                    else:
                        $counter = 1;
                        // $batch_ref = date('M-y-').$counter;
                        $batch_ref = date('ymd.').$counter;
                        $batch     = $counter;
                    endif;

                    $Invoice = Invoice::find($invoice_id);
                    $Invoice->invoice_ref = $ref + 1;
                    $Invoice->status    = 'Pending';
                    $Invoice->batch_ref = $batch_ref;
                    $Invoice->batch = $batch;
                    $supplier = User::where('id', $Invoice->supplier_id)->first();
                    $Link = Link::where('buyer_id', $Invoice->buyer_id)->where('supplier_id', $Invoice->supplier_id)->where('is_default', 1)->latest()->first();
                    if (!$supplier->is_supplier_approval || (isset($Link) && $Link->fees_incurred_by == 'buyer')) {
                        $Invoice->supplier_approval = "Approved";
                    }
                    $ref++;
                    $Invoice->save();
                }
            endforeach;
            return response()->json('success');
            // return redirect()->back()->with("success","Successfully confirmed invoice(s).");
        endif;

        foreach($invoice_ids as $invoice_id):
            if ($invoice_id != '') {
                $Invoice = Invoice::find($invoice_id);
                $Invoice->delete();
            }
        endforeach;

        return response()->json('success');
        // return redirect()->back()->with("success","Successfully delete invoice(s).");
    }

    public function PayInvoice($id) // Pay invoice
    {
        $Invoice = Invoice::find($id);
        $Invoice->payment_status = "Paid";
        $Invoice->estimated_disbursement_date = date('Y-m-d');
        $Invoice->admin_approval = "Approved";
        $Invoice->pay_at = Carbon::now();

        // $get_last_record = Invoice::orderBy('invoice_ref', 'desc')->whereNotNull('invoice_ref')->first();
        //
        // if (isset($get_last_record)) {
        //     $ref = $get_last_record->invoice_ref;
        //     $Invoice->invoice_ref = $ref+1;
        // }else{
        //     $Invoice->invoice_ref = 1;
        // }

        if ($Invoice->save()) {
            return redirect()->back()->with("success","Invoice paid successfully.");
        }else{
            return redirect()->back()->with("error","Failed to pay Invoice.")->withInput();
        }
    }

    public function RejectInvoice($id) // reject invoice
    {
        $Invoice = Invoice::find($id);
        $Invoice->payment_status = "Rejected";
        if ($Invoice->save()) {
            return redirect()->back()->with("success","Invoice paid successfully.");
        }else{
            return redirect()->back()->with("error","Failed to pay Invoice.")->withInput();
        }
    }


    public function AmountRecievable() // amount recievable list in admin
    {
        $invoices  = Invoice::where('payment_status', 'Paid')
                            ->where('due_status', '!=', 'Paid') // New query after feedback to add status check
                            ->latest()->get();
        $suppliers = User::where('role', 'supplier')->latest()->get();
        $buyers = User::where('role', 'buyer')->latest()->get();
        $investors = User::where('role', 'investor')->latest()->get();

        return view('dashboards.admins.amountrecievable.index', compact('invoices', 'suppliers', 'buyers', 'investors'));
    }

    public function ReceivedAmount($id) // recieve invoice
    {
        $Invoice = Invoice::find($id);
        $Invoice->due_status = "Paid";
        $Invoice->old_disbursement_date = date('Y-m-d');
        // $Invoice->estimated_disbursement_date = date('Y-m-d');
        if ($Invoice->save()) {
            // InvoicePaidAlert::create([
            //     'buyer_id'   => $Invoice->buyer_id,
            //     'invoice_id' => $Invoice->id
            // ]);
            return redirect()->back()->with("success","Invoice updated successfully.");
        }else{
            return redirect()->back()->with("error","Failed to update Invoice.");
        }
    }

    public function batchReceivedAmount($batch) // recieve invoice
    {
        $Invoices = Invoice::where('batch_id', $batch)->get();
        foreach ($Invoices as $Invoice) {
            $Invoice->due_status = "Paid";
            $Invoice->old_disbursement_date = date('Y-m-d');
            // $Invoice->estimated_disbursement_date = date('Y-m-d');
            $Invoice->save();
        }
        // if ($Invoice->save()) {
            // InvoicePaidAlert::create([
            //     'buyer_id'   => $Invoice->buyer_id,
            //     'invoice_id' => $Invoice->id
            // ]);
            return redirect()->back()->with("success", "Invoice updated successfully.");
        // }else{
        //     return redirect()->back()->with("error","Failed to update Invoice.");
        // }
    }

    public function FilterAmountRecievable(Request $request) // filter amount recievable
    {
        $batch_refrence = $request->batch_refrence;
        $payment_status = $request->payment_status;

        if (isset($batch_refrence) && !isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->where('batch_ref', $batch_refrence)->latest()->get();
        elseif(!isset($batch_refrence) && isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->where('due_status', $payment_status)->latest()->get();
        elseif(!isset($batch_refrence) && !isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->latest()->get();
        elseif(isset($batch_refrence) && isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->where('batch_ref', $batch_refrence)->where('due_status', $payment_status)->latest()->get();
        endif;

        $suppliers = User::where('role', 'supplier')->latest()->get();
        $buyers = User::where('role', 'buyer')->latest()->get();

        return view('dashboards.admins.amountrecievable.index', compact('invoices', 'suppliers', 'buyers'));
    }

    public function FilterAmountPayable(Request $request) // filter amount payable in buyer
    {
        $batch_refrence = $request->batch_refrence;
        $payment_status = $request->payment_status;

        if (isset($batch_refrence) && !isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')
                                ->where('batch_ref', $batch_refrence)
                                ->where('buyer_id', Auth::user()->id)
                                ->latest()
                                ->get();
        elseif(!isset($batch_refrence) && isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')
                                ->where('due_status', $payment_status)
                                ->where('buyer_id', Auth::user()->id)
                                ->latest()
                                ->get();
        elseif(!isset($batch_refrence) && !isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')
                                ->where('buyer_id', Auth::user()->id)
                                ->latest()
                                ->get();
        elseif(isset($batch_refrence) && isset($payment_status)):
            $invoices = Invoice::where('payment_status', 'Paid')
                                ->where('batch_ref', $batch_refrence)
                                ->where('due_status', $payment_status)
                                ->where('buyer_id', Auth::user()->id)
                                ->latest()
                                ->get();
        endif;

        return view('dashboards.buyer.amountpayable.index', compact('invoices'));
    }

    public function UpdateGracePeriod(Request $request, $id) // update grace period
    {
        $Invoice = Invoice::find($id);
        $Invoice->end_of_grace_period = $request->end_of_grace_period;
        if ($Invoice->save()) {
            return redirect()->back()->with("success","Invoice updated successfully.");
        }else{
            return redirect()->back()->with("error","Failed to update Invoice.");
        }
    }

    public function calculateCountSunAvg(Request $request) // Calculate Invoices Count, Sum and Average
    {
        if ($request->ajax()) {
            if ($request->page == 'amountRecievable') {
                $dataArray = Invoice::where('payment_status', 'Paid')
                                    ->where('due_status', '!=', 'Paid');
                if ($request->idsStr) {
                    $idsStr = explode(',', $request->idsStr);
                    $dataArray = $dataArray->whereIn('id', $idsStr);
                }
                if ($request->batchIdsStr) {
                    $batchIdsStr = explode(',', $request->batchIdsStr);
                    $dataArray = $dataArray->whereIn('batch_id', $batchIdsStr);
                }
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'admin_invoices' || $request->page == 'invoices-to-pay') {
                $dataArray  = Invoice::where('status', '!=', 'Uploaded');
                if ($request->page == 'invoices-to-pay') {
                    $dataArray  = $dataArray->where('payment_status', 'In Process')
                                            ->where('supplier_approval', 'Approved');
                }
                if ($request->idsStr) {
                    $idsStr = explode(',', $request->idsStr);
                    $dataArray = $dataArray->whereIn('id', $idsStr);
                }
                if ($request->batchIdsStr) {
                    $batchIdsStr = explode(',', $request->batchIdsStr);
                    $dataArray = $dataArray->whereIn('batch_id', $batchIdsStr);
                }
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'adminbuyers') {
                $dataArray = User::where('role', 'buyer');
                if ($request->values) {
                  if (in_array($request->field, ['admin_fee', 'ddf_30_days', 'ddf_60_days', 'ddf_90_days', 'late_fee', 'grace_period', 'buyer_limit'])) {
                      $values = explode(',', $request->values);
                      $userIds = Setting::whereIn($request->field, $values)->pluck('user_id')->toArray();

                      if (in_array('NULL', $values)) {
                        $nullUserIds = Setting::where($request->field, NULL)->pluck('user_id')->toArray();
                        $userIds = array_merge($userIds, $nullUserIds);
                      }
                      $dataArray = User::where('role', 'buyer')->whereIn('id', $userIds);
                  } else {
                      $values = explode(',', $request->values);
                      $dataArray = $dataArray->whereIn($request->field, $values);
                  }
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'adminsuppliers') {
                $dataArray = User::where('role', 'supplier');
                if ($request->values) {
                  if (in_array($request->field, ['bank_name', 'account_no', 'branch_code'])) {
                      $values = explode(',', $request->values);
                      $userIds = Bank::whereIn($request->field, $values)->pluck('user_id')->toArray();

                      // if (in_array('NULL', $values)) {
                      //   $nullUserIds = Bank::where($request->field, NULL)->pluck('user_id')->toArray();
                      //   $userIds = array_merge($userIds, $nullUserIds);
                      // }
                      $dataArray = User::where('role', 'supplier')->whereIn('id', $userIds);
                  } else {
                      $values = explode(',', $request->values);
                      $dataArray = $dataArray->whereIn($request->field, $values);
                  }
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'link-supplier') {
                $val = 0;
                $user = User::find($request->id);
                $dataArray = Link::where('buyer_id', $request->id)->where('is_default', 1)->latest()->get();
            } elseif ($request->page == 'link-buyer') {
                $val = 0;
                $user = User::find($request->id);
                $dataArray = Link::where('supplier_id', $request->id)->where('is_default', 1)->latest()->get();
            } elseif ($request->page == 'amount-payable') {
                $dataArray = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->latest();
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                if ($request->idsStr) {
                    $idsStr = explode(',', $request->idsStr);
                    $dataArray = $dataArray->whereIn('id', $idsStr);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'track-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id)->latest();
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                if ($request->idsStr) {
                    $idsStr = explode(',', $request->idsStr);
                    $dataArray = $dataArray->whereIn('id', $idsStr);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'uploaded-invoices') {
                $dataArray = Invoice::where('status', 'Uploaded')->where('buyer_id', Auth::user()->id)->latest();
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'new-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('supplier_approval', 'Pending')->where('supplier_id', Auth::user()->id)->latest();
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'all-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('supplier_approval', '!=', 'Pending')->where('supplier_id', Auth::user()->id)->latest();
                if ($request->values) {
                    $values = explode(',', $request->values);
                    $dataArray = $dataArray->whereIn($request->field, $values);
                }
                if ($request->idsStr) {
                    $idsStr = explode(',', $request->idsStr);
                    $dataArray = $dataArray->whereIn('id', $idsStr);
                }
                $dataArray = $dataArray->get();
            } elseif ($request->page == 'admininvestors') {
                $dataArray = User::where('role', 'investor')->latest()->get();
            } else {
                $dataArray = [];
            }

            $count = count($dataArray);
            if ($request->idsStr) {
                $idsStr = explode(',', $request->idsStr);
                $idsStr = array_unique($idsStr);
                $count = count($idsStr);
            }
            if ($request->batchIdsStr) {
                $batchIdsStr = explode(',', $request->batchIdsStr);
                $batchIdsStr = array_unique($batchIdsStr);
                $count = count($batchIdsStr);
            }
            $sum = 0;

            foreach ($dataArray as $data) {
                if ($request->colName == 'zvilo_invoice_ref') {
                    $sum = $sum + $data->invoice_ref;
                } elseif ($request->colName == 'amount') {
                    $sum = $sum + $data->amount;
                } elseif ($request->colName == 'payment_terms_days') {
                    $sum = $sum + $data->payment_terms_days;
                } elseif ($request->colName == 'admin_fee' && !in_array($request->page, ['admin_invoices', 'new-invoices', 'all-invoices', 'invoices-to-pay'])) {
                    $sum = $sum + optional($data->setting)->admin_fee;
                } elseif ($request->colName == '30_day_discount_fee') {
                    $sum = $sum + optional($data->setting)->ddf_30_days;
                } elseif ($request->colName == '60_day_discount_fee') {
                    $sum = $sum + optional($data->setting)->ddf_60_days;
                } elseif ($request->colName == '90_day_discount_fee') {
                    $sum = $sum + optional($data->setting)->ddf_90_days;
                } elseif ($request->colName == 'late_fee') {
                    $sum = $sum + optional($data->setting)->late_fee;
                } elseif ($request->colName == 'grace_period') {
                    $sum = $sum + optional($data->setting)->grace_period;
                } elseif ($request->colName == 'buyer_limit') {
                    $sum = $sum + optional($data->setting)->buyer_limit;
                } elseif ($request->colName == 'link_admin_fee') {
                    if (isset($data->admin_fee)) {
                        $val = $data->admin_fee;
                    } elseif (isset($user->setting->admin_fee)) {
                        $val = $user->setting->admin_fee;
                    }
                    $sum = $sum + $val;
                } elseif ($request->colName == 'link_30_day_discount_fee') {
                    if (isset($data->ddf_30_days)) {
                        $val = $data->ddf_30_days;
                    } elseif (isset($user->setting->ddf_30_days)) {
                        $val = $user->setting->ddf_30_days;
                    }
                    $sum = $sum + $val;
                } elseif ($request->colName == 'link_60_day_discount_fee') {
                    if (isset($data->ddf_60_days)) {
                        $val = $data->ddf_60_days;
                    } elseif (isset($user->setting->ddf_60_days)) {
                        $val = $user->setting->ddf_60_days;
                    }
                    $sum = $sum + $val;
                } elseif ($request->colName == 'link_90_day_discount_fee') {
                    if (isset($data->ddf_90_days)) {
                        $val = $data->ddf_90_days;
                    } elseif (isset($user->setting->ddf_90_days)) {
                        $val = $user->setting->ddf_90_days;
                    }
                    $sum = $sum + $val;
                } elseif ($request->colName == 'link_grace_period') {
                    if (isset($data->grace_period)) {
                        $val = $data->grace_period;
                    } elseif (isset($user->setting->grace_period)) {
                        $val = $user->setting->grace_period;
                    }
                    $sum = $sum + $val;
                } elseif (in_array($request->colName, [
                        'Total_admin_and_discount',
                        'late_fees_earned',
                        'total_fees',
                        'total_amount_outstanding',
                        'disbursement_amount',
                        'admin_fee_earned',
                        'admin_fee',
                        'discount_fee_earned',
                        'applied_discount_fee',
                        'late_fees',
                        'euribor_per',
                        'euribor_fee_earned',
                        'applied_late_fees',
                        'applied_tenor'
                    ])) {
                    // New Code Start
                    $sum = $this->calculateFormula($request, $data, $sum);
                    // New code End
                } elseif ($request->colName == 'investor_ownership') {
                    $sum = $sum + optional($data->invoiceDistribution)->investor_ownership;
                } elseif ($request->colName == 'zvilo_ownership') {
                    $sum = $sum + optional($data->invoiceDistribution)->zvilo_ownership;
                } elseif ($request->colName == 'amount_paid') {
                    $amountPaidCal = $data->amount;
                    if ($data->due_status == "Pending") {
                        $amountPaidCal = 0;
                    }
                    $sum = $sum + $amountPaidCal;
                } elseif ($request->colName == 'facility_size') {
                    $sum = $sum + $data->facility_size;
                } elseif ($request->colName == 'drawdown_amount') {
                    $sum = $sum + $data->drawdown_amount;
                } elseif ($request->colName == 'investor_fees') {
                    $sum = $sum + $data->investor_fees;
                }
            }
            $average = $sum/$count;

            return response()->json([
                'count'   => $count,
                'average' => number_format($average, 2),
                'sum'     => number_format($sum, 2)
            ]);
        }
    }

    public function calculateFormula(Request $request, $data, $sum) // Sum Calculation invoices
    {
        $invoice     = $data;
        $appliedTenor = 0;
        $buyer_id    = $invoice->buyer_id;
        $supplier_id = $invoice->supplier_id;
        $Link = Link::where('buyer_id', $buyer_id)
                    ->where('supplier_id', $supplier_id)
                    ->where('created_at', '<=', $invoice->created_at)
                    ->latest()->first();
        $buyer = User::find($buyer_id);
        $payment_type = "";
        if (isset($Link)) {
            $payment_type = $Link->payment_type;
            if ($payment_type == "Monthly") {
                $payment_term = $invoice->payment_terms_days;
                if ($payment_term <= 30) {
                    $appliedTenor = 30;
                } elseif ($payment_term >= 31 && $payment_term <= 60) {
                    $appliedTenor = 60;
                } elseif ($payment_term >= 61 && $payment_term <= 90) {
                    $appliedTenor = 90;
                } else {
                    $appliedTenor = 90;
                }
            } elseif ($payment_type == "Daily") {
                $payment_term = $invoice->payment_terms_days;
                if ($payment_term > 90) {
                  $appliedTenor = 90;
                } else {
                  $appliedTenor = $payment_term;
                }
            }
        }

        if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
            $appliedTenor = $Link->fixed_tenor;
        }

        if (isset($invoice->tenor_days)) {
            $appliedTenor = $invoice->tenor_days;
        }
        if (isset($invoice->estimated_disbursement_date)) {
            $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
            $RepaymentDueDate = date('d-m-Y', strtotime($disbursement_date->addDay($appliedTenor)));
        }

        if (isset($Link->admin_fee)) {
            $admin_fee          = $Link->admin_fee;
            $day30discountfee   = $Link->ddf_30_days;
            $day60discountfee   = $Link->ddf_60_days;
            $day90discountfee   = $Link->ddf_90_days;
        } elseif (isset($buyer->setting->admin_fee)) {
            $admin_fee = $buyer->setting->admin_fee;
            $day30discountfee   = $buyer->setting->ddf_30_days;
            $day60discountfee   = $buyer->setting->ddf_60_days;
            $day90discountfee   = $buyer->setting->ddf_90_days;
        } else {
            $admin_fee = 0;
            $day30discountfee = 0;
            $day60discountfee = 0;
            $day90discountfee = 0;
        }

        $admin_fee_earned = ($invoice->amount/100)*$admin_fee;

        // Applied discount fee
        if ($payment_type == "Monthly") {
            if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                $appliedDiscountFee = ($day30discountfee/30)*30;
            } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                $appliedDiscountFee = ($day60discountfee/60)*60;
            } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                $appliedDiscountFee = ($day90discountfee/90)*90;
            }

            $appliedDiscountFee = number_format((float)$appliedDiscountFee, 2, '.', '');

            $DiscountFeeEarned = ($invoice->amount/100)*$appliedDiscountFee;
        } else {
            $appliedDiscountFee = 0;

            if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                $appliedDiscountFee = ($day30discountfee/30)*$appliedTenor;
            } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                $appliedDiscountFee = ($day60discountfee/60)*$appliedTenor;
            } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                $appliedDiscountFee = ($day90discountfee/90)*$appliedTenor;
            }

            $appliedDiscountFee = number_format((float)$appliedDiscountFee, 2, '.', '');

            $DiscountFeeEarned = ($invoice->amount/100)*$appliedDiscountFee;
        }

        $TotalFee = $DiscountFeeEarned+$admin_fee_earned;
        $paymentDueDate = 'N/A';
        if(isset($RepaymentDueDate) && isset($invoice->estimated_disbursement_date)):
            $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
            $maturityDate      = $disbursement_date->addDay($appliedTenor);
            if (isset($buyer->setting->grace_period)) {
                $paymentDueDate = date('d-m-Y', strtotime($maturityDate->addDay($buyer->setting->grace_period)));
            }
        endif;

        if ($paymentDueDate != 'N/A') {
            $lateFees = 0;
            $today = date('Y-m-d');
            $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);
            $fromToday = new \Carbon\Carbon($today);
            $daysTillPD = $fromToday->diffInDays($toPaymentDueDate);

            if ($fromToday > $toPaymentDueDate):
                $lateFeePercentage = ($invoice->buyer->setting->late_fee) / 30;
                $count1 = ($lateFeePercentage / $invoice->amount);
                $count2 = $count1 * 100;
                $lateFees = ABS($daysTillPD) * $count2;
            endif;
            $totalAmountOutstanding = $lateFees + $invoice->amount;
        }

        $euriborPer = 0;
        if (isset($Link) && $Link->euribor_per && $Link->euribor_type == '3_months') {
            $euriborPer = $Link->euribor_per * ($appliedTenor / 365);
        }

        $euriborPerNumber = sprintf('%0.3f', $euriborPer);
        $euriborFeeEaned = ($euriborPerNumber/100) * $invoice->amount;

        $totalFeesIncLateFee = (isset($lateFees) ? $lateFees :  0) + $TotalFee + $euriborFeeEaned;
        // $disbursementAmount = $invoice->amount-$TotalFee;

        if ($invoice->payment_status == 'Paid') {
            if ($invoice->due_status == "Paid") {
                $totalAmountOutstanding = 0;
            } else {
                if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
                    $totalAmountOutstanding = $invoice->amount + $totalFeesIncLateFee;
                } else {
                    // if (isset($totalAmountOutstanding)) {
                        if ($invoice->override_late_fees) {
                            $totalAmountOutstanding = $invoice->amount + $invoice->override_late_fees;
                        } else {
                            $totalAmountOutstanding = $invoice->amount + (isset($lateFees) ? $lateFees : 0);
                        }
                    // } else {
                    //     $totalAmountOutstanding = 0;
                    // }
                }
            }
        } else {
            $totalAmountOutstanding = 0;
        }

        $totalFeeExclLateFees = $euriborFeeEaned + $TotalFee;
        if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
            $disbursementAmount = $invoice->amount;
        } else {
            $disbursementAmount = $invoice->amount - $totalFeeExclLateFees;
        }

        $appliedLateFees = 0;
        if ($invoice->override_late_fees) {
            $appliedLateFees = $invoice->override_late_fees;
        } elseif (isset($lateFees)) {
            $appliedLateFees = isset($lateFees) ? $lateFees : 0;
        }

        if ($request->colName == 'Total_admin_and_discount') {
            $sum = $sum + $totalFeeExclLateFees;
        } elseif (in_array($request->colName, ['late_fees_earned', 'late_fees']) && isset($lateFees)) {
            $sum = $sum + $lateFees;
        } elseif (in_array($request->colName, ['total_amount_outstanding']) && isset($totalAmountOutstanding)) {
            $sum = $sum + $totalAmountOutstanding;
        } elseif ($request->colName == 'total_fees') {
            $sum = $sum + $totalFeesIncLateFee;
        } elseif ($request->colName == 'disbursement_amount') {
            $sum = $sum + $disbursementAmount;
        } elseif ($request->colName == 'admin_fee_earned') {
            $sum = $sum + $admin_fee_earned;
        } elseif ($request->colName == 'admin_fee') {
            $sum = $sum + $admin_fee;
        } elseif ($request->colName == 'discount_fee_earned') {
            $sum = $sum + $DiscountFeeEarned;
        } elseif ($request->colName == 'applied_discount_fee') {
            $sum = $sum + $appliedDiscountFee;
        } elseif ($request->colName == 'euribor_per') {
            $sum = $sum + $euriborPerNumber;
        } elseif ($request->colName == 'euribor_fee_earned') {
            $sum = $sum + $euriborFeeEaned;
        } elseif ($request->colName == 'applied_tenor') {
            $sum = $sum + $appliedTenor;
        } elseif ($request->colName == 'applied_late_fees') {
            $sum = $sum + $appliedLateFees;
        }

        return $sum;
    }

    public function dropdownEntries(Request $request) // Get the filter dropdown entries
    {
        if ($request->ajax()) {
            $newDataArray = [];
            $dataArray = [];
            if ($request->page == 'admin_invoices' || $request->page == 'invoices-to-pay') {
                if ($request->colName == 'invoice_ref') {
                    $dataArray = Invoice::where('status', '!=', 'Uploaded');
                    if ($request->page == 'invoices-to-pay') {
                        $dataArray  = $dataArray->where('payment_status', 'In Process')
                                                ->where('supplier_approval', 'Approved');
                    }
                    $dataArray  = $dataArray->orderBy('invoice_ref', 'asc')->pluck('invoice_ref')->toArray();
                } elseif (!in_array($request->colName, ['invoice_ref', 'repayment_due_date', 'investor_id'])) {
                    $dataArray = Invoice::where('status', '!=', 'Uploaded');
                    if ($request->page == 'invoices-to-pay') {
                        $dataArray  = $dataArray->where('payment_status', 'In Process')
                                                ->where('supplier_approval', 'Approved');
                    }
                    $dataArray  = $dataArray->latest()->pluck($request->colName)->toArray();
                } elseif (in_array($request->colName, ['repayment_due_date'])) {
                    $invoices = Invoice::where('status', '!=', 'Uploaded');
                    if ($request->page == 'invoices-to-pay') {
                        $invoices  = $invoices->where('payment_status', 'In Process')
                                              ->where('supplier_approval', 'Approved');
                    }
                    $invoices  = $invoices->get();
                    $i = 0;
                    $resultArr = [];
                    $dataArray = [];
                    foreach ($invoices as $key => $invoice) {
                        $Link = Link::where('supplier_id', $invoice->supplier_id)->where('buyer_id', $invoice->buyer_id)->where('created_at', '<=', $invoice->created_at)->latest()->first();
                        $appliedTenor = 0;
                        if (isset($Link)) {
                            $payment_type = $Link->payment_type;
                            if ($payment_type == "Monthly") {
                                $payment_term = $invoice->payment_terms_days;
                                if ($payment_term <= 30) {
                                    $appliedTenor = 30;
                                } elseif ($payment_term >= 31 && $payment_term <= 60) {
                                    $appliedTenor = 60;
                                } elseif ($payment_term >= 61 && $payment_term <= 90) {
                                    $appliedTenor = 90;
                                } else {
                                    $appliedTenor = 90;
                                }
                            } elseif($payment_type == "Daily") {
                                $payment_term = $invoice->payment_terms_days;
                                if ($payment_term > 90) {
                                    $appliedTenor = 90;
                                } else {
                                    $appliedTenor = $payment_term;
                                }
                            }

                            if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
                                $appliedTenor = $Link->fixed_tenor;
                            }

                            if (isset($invoice->tenor_days)) {
                                $appliedTenor = $invoice->tenor_days;
                            }
                            if ($request->colName == 'repayment_due_date') {
                                $RepaymentDueDate = '';
                                if (isset($invoice->estimated_disbursement_date)) {
                                    $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                                    $RepaymentDueDate = date('d-m-Y', strtotime($disbursement_date->addDay($appliedTenor)));
                                }
                                $dataArray[$i++] = $RepaymentDueDate;
                            }
                        }

                    }
                } else {
                      if ($request->colName == 'investor_id') {
                          $invoicesIds = Invoice::where('status', '!=', 'Uploaded')->pluck('id')->toArray();
                          $dataArray = InvoiceDistribution::whereIn('invoice_id', $invoicesIds)->pluck('investor_id')->toArray();
                      } else {
                          $dataArray = [];
                      }
                }

                $newDataArray = [];
                if ($request->colName == 'buyer_id') {
                    $newDataArray = User::where('role', 'buyer')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }

                if ($request->colName == 'investor_id') {
                    $newDataArray = User::where('role', 'investor')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }

                if ($request->colName == 'supplier_id') {
                    $newDataArray = User::where('role', 'supplier')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }

            } elseif ($request->page == 'adminbuyers') {
                if (in_array($request->colName, ['admin_fee', 'ddf_30_days', 'ddf_60_days', 'ddf_90_days', 'late_fee', 'grace_period', 'buyer_limit'])) {
                    $dataArrayIds = User::where('role', 'buyer')->pluck('id')->toArray();
                    $dataArray = Setting::whereIn('user_id', $dataArrayIds)->latest()->pluck($request->colName)->toArray();
                } else {
                    $dataArray = User::where('role', 'buyer')->latest()->pluck($request->colName)->toArray();
                }
            } elseif ($request->page == 'adminsuppliers') {
                if (in_array($request->colName, ['bank_name', 'account_no', 'branch_code'])) {
                    $dataArrayIds = User::where('role', 'supplier')->pluck('id')->toArray();
                    $dataArray = Bank::whereIn('user_id', $dataArrayIds)->latest()->pluck($request->colName)->toArray();
                } else {
                    $dataArray = User::where('role', 'supplier')->latest()->pluck($request->colName)->toArray();
                }
            } elseif ($request->page == 'amountRecievable') {
                if (in_array($request->colName, ['receivable_status', 'ageing_categories'])) {
                    $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->get();
                    $i = 0;
                    foreach ($invoices as $key => $invoice) {
                        $Link = $this->linkFun($invoice);
                        $appliedTenor = 0;
                        if (isset($Link)) {
                            $appliedTenor = $this->appliedTenor($invoice, $Link);
                        }

                        if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
                            $appliedTenor = $Link->fixed_tenor;
                        }

                        if (isset($invoice->tenor_days)) {
                            $appliedTenor = $invoice->tenor_days;
                        }
                        if (isset($invoice->estimated_disbursement_date)) {
                            $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                            $RepaymentDueDate = date('Y-m-d', strtotime($disbursement_date->addDay($appliedTenor)));
                        } else {
                            $RepaymentDueDate = date('Y-m-d');
                        }

                        $paymentDueDate = 'N/A';
                        if (isset($RepaymentDueDate) && isset($invoice->estimated_disbursement_date)) {
                            $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                            $maturityDate      = $disbursement_date->addDay($appliedTenor);
                            $buyer_id    = $invoice->buyer_id;
                            $buyer = User::find($buyer_id);
                            if (isset($buyer->setting->grace_period)) {
                                $paymentDueDate = date('d-m-Y', strtotime($maturityDate->addDay($buyer->setting->grace_period)));
                            }
                        }

                        $today = date('Y-m-d');
                        $toRepaymentDueDate = new \Carbon\Carbon($RepaymentDueDate);
                        $fromToday = new \Carbon\Carbon($today);
                        $daysTillMaturity = $fromToday->diffInDays($toRepaymentDueDate);

                        if ($toRepaymentDueDate < $fromToday) {
                            $daysTillMaturity = "-".$daysTillMaturity;
                        }

                        $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);

                        $daysTillPD = $fromToday->diffInDays($toPaymentDueDate);

                        if ($toPaymentDueDate < $fromToday) {
                            $daysTillPD = "-".$daysTillPD;
                        }

                        if ($request->colName == 'receivable_status') {
                            if ($invoice->due_status == "Paid") {
                                $dataArray[$i++] = "Paid";
                            } else {
                                if($daysTillMaturity >= 0) {
                                    $dataArray[$i++] = "Not Due";
                                } elseif ($daysTillMaturity < 0) {
                                    if ($daysTillPD >= 0) {
                                        $dataArray[$i++] = "Within Grace Period";
                                    } else {
                                        $dataArray[$i++] = "Overdue";
                                    }
                                }
                            }
                        }

                        if ($request->colName == 'ageing_categories') {
                            if($daysTillMaturity >= 0) {
                                if ($daysTillPD <= 30) {
                                    $dataArray[$i++] = "Due in 0-30 days";
                                } elseif ($daysTillPD > 30 && $daysTillPD <= 60) {
                                    $dataArray[$i++] = "Due in 31-60 days";
                                } elseif ($daysTillPD > 60 && $daysTillPD <= 90) {
                                    $dataArray[$i++] = "Due in 61-90 days";
                                } else {
                                    $dataArray[$i++] = "Due in 90+ days";
                                }
                            } elseif ($daysTillMaturity < 0) {
                                if ($daysTillPD >= 0) {
                                    $dataArray[$i++] = "Within Grace Period";
                                } else {
                                    if (abs($daysTillPD) <= 30) {
                                        $dataArray[$i++] = "Overdue 1-30 days";
                                    } elseif (abs($daysTillPD) > 30 && abs($daysTillPD) <= 60) {
                                        $dataArray[$i++] = "Overdue 31-60 days";
                                    } elseif (abs($daysTillPD) > 60 && abs($daysTillPD) <= 90) {
                                        $dataArray[$i++] = "Overdue 61-90 days";
                                    } else {
                                        $dataArray[$i++] = "Overdue 90+ days";
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if ($request->colName == 'investor_id') {
                        $invoicesIds = Invoice::where('payment_status', 'Paid')->where('due_status', '!=', 'Paid')->pluck('id')->toArray();
                        $dataArray = InvoiceDistribution::whereIn('invoice_id', $invoicesIds)->pluck('investor_id')->toArray();
                    } else {
                        $dataArray = Invoice::where('payment_status', 'Paid')
                                            ->where('due_status', '!=', 'Paid')
                                            ->pluck($request->colName)->toArray();
                    }
                }

                if ($request->colName == 'investor_id') {
                    $newDataArray = User::where('role', 'investor')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }

                if ($request->colName == 'buyer_id') {
                    $newDataArray = User::where('role', 'buyer')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }
            } elseif ($request->page == 'uploaded-invoices') {
                $dataArray = Invoice::where('status', 'Uploaded')->where('buyer_id', Auth::user()->id)->pluck($request->colName)->toArray();

                if ($request->colName == 'supplier_id') {
                    $newDataArray = User::where('role', 'supplier')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }
            } elseif ($request->page == 'track-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id)->pluck($request->colName)->toArray();

                if ($request->colName == 'supplier_id') {
                    $newDataArray = User::where('role', 'supplier')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }
            } elseif ($request->page == 'amount-payable') {
                if ($request->colName == 'status') {
                    $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->latest()->get();

                    $i = 0;
                    foreach ($invoices as $key => $invoice) {
                        $Link = $this->linkFun($invoice);
                        $appliedTenor = 0;
                        if (isset($Link)) {
                            $appliedTenor = $this->appliedTenor($invoice, $Link);
                        }

                        if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
                            $appliedTenor = $Link->fixed_tenor;
                        }

                        if (isset($invoice->tenor_days)) {
                            $appliedTenor = $invoice->tenor_days;
                        }

                        if (isset($invoice->estimated_disbursement_date)) {
                            $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                            $RepaymentDueDate = date('Y-m-d', strtotime($disbursement_date->addDay($appliedTenor)));;
                        } else {
                            $RepaymentDueDate = date('Y-m-d');
                        }

                        $today = date('Y-m-d');
                        $to = new \Carbon\Carbon($RepaymentDueDate);
                        $from = new \Carbon\Carbon($today);

                        $days = $from->diffInDays($to);
                        $daysShow = $days;

                        $end_of_grace_period = $to->addDay($invoice->buyer->setting->grace_period);
                        $end_of_grace_period_carbon = new \Carbon\Carbon($end_of_grace_period);

                        $days_till_end_of_grace_period = $from->diffInDays($end_of_grace_period);
                        if ($end_of_grace_period_carbon < $from) {
                            $days_till_end_of_grace_period = "-".$days_till_end_of_grace_period;
                        }

                        if ($RepaymentDueDate < $today) {
                            $daysShow = "-".$days;
                            $days = -$days;
                        }

                        if ($invoice->due_status == "Paid") {
                            $dataArray[$i++] = 'Paid';
                        } else {
                            if ($days >= 0) {
                                $dataArray[$i++] = 'Not Due';
                            } elseif ($days < 0) {
                                $dataArray[$i++] = 'Overdue';
                            }
                        }
                    }
                } else {
                    $dataArray = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->pluck($request->colName)->toArray();
                }
            } elseif ($request->page == 'new-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('supplier_approval', 'Pending')->where('supplier_id', Auth::user()->id)->pluck($request->colName)->toArray();

                if ($request->colName == 'buyer_id') {
                    $newDataArray = User::where('role', 'buyer')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }
            } elseif ($request->page == 'all-invoices') {
                $dataArray = Invoice::where('status', '!=', 'Uploaded')->where('supplier_approval', '!=', 'Pending')->where('supplier_id', Auth::user()->id)->pluck($request->colName)->toArray();

                if ($request->colName == 'buyer_id') {
                    $newDataArray = User::where('role', 'buyer')->whereIn('id', $dataArray)->pluck('company_name', 'id')->toArray();
                }
            }

            $dataArray = array_unique($dataArray);
            $dataArray = array_values($dataArray);

            return response()->json([
                'data'  => $dataArray,
                'name'  => $newDataArray
            ]);
        }
    }
}
