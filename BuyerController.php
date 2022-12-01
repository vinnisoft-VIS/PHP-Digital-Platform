<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Auth;
use Session;
use App\Models\Invoice;
use App\Models\Link;
use App\Models\AcknowledgementForm;
use Carbon\Carbon;
// use App\Models\InvoicePaidAlert;
use App\Exports\ExportLinkedSupplier;
use App\Exports\ExportBatchApproval;
use Maatwebsite\Excel\Facades\Excel;
use App\Mail\AcknowledgementForm as AcknowledgementFormMail;
use Mail;
use PDF;

class BuyerController extends Controller
{
    public function index(Request $request) // buyers dashboard
    {
        // $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->latest()->get();
        $buyer = Auth::user();
        $invoicesData = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id);
        $supplierId = 0;
        if ($request->supplier && $request->supplier != 'all') {
            $supplierId = $request->supplier;
            $invoicesData = $invoicesData->where('supplier_id', $request->supplier);
        }
        $batchId = 0;
        if ($request->batch && $request->batch != 'all') {
            $batchId = $request->batch;
            $invoicesData = $invoicesData->where('batch_id', $request->batch);
        }
        $invoices = $invoicesData->latest()->get();
        $noOfSuppliers = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id)->pluck('supplier_id')->toArray();
        $noOfSuppliers = array_unique($noOfSuppliers);
        $suppliersArr = array_values($noOfSuppliers);
        $noOfSuppliers = count($noOfSuppliers);
        $noInvoicesFinanced = count($invoices);
        $suppliers = User::whereIn('id', $suppliersArr)->get();

        $batchesArr = Invoice::where('status', '!=', 'Uploaded')->where('buyer_id', Auth::user()->id)->whereNotNull('batch_id')->pluck('batch_id')->toArray();
        $batchesArr = array_unique($batchesArr);
        $batches = array_values($batchesArr);

        // $buyerLimit = Setting::where('user_id', Auth::id())->first();
        // if (isset($buyerLimit) && $buyerLimit->buyer_limit) {
        //
        // }
        $buyerLimit = isset($buyer->setting) ? $buyer->setting->buyer_limit : 0;

        $grandTotalFinanced = 0;
        $due_in_0_30_days = 0;
        $due_in_31_60_days = 0;
        $due_in_61_90_days = 0;
        $due_in_90_days = 0;
        $overdue_1_30_days = 0;
        $overdue_31_60_days = 0;
        $overdue_61_90_days = 0;
        $overdue_90_days = 0;
        $within_grace_period = 0;
        $due_today = 0;
        foreach ($invoices as $key => $invoice) {
            $grandTotalFinanced = $grandTotalFinanced + $invoice->amount;

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
                } elseif ($payment_type == "Daily") {
                    $payment_term = $invoice->payment_terms_days;
                    if ($payment_term > 90) {
                        $appliedTenor = 90;
                    } else {
                      $appliedTenor = $payment_term;
                    }
                } else {
                    $appliedTenor = $Link->fixed_tenor;
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

            $daysTillPD = 0;
            if ($paymentDueDate != 'N/A') {
                $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);
                $daysTillPD = $fromToday->diffInDays($toPaymentDueDate);

                if ($toPaymentDueDate < $fromToday) {
                    $daysTillPD = "-".$daysTillPD;
                }
            }

            if ($invoice->due_status == 'Pending') {
                if ($daysTillMaturity == 0) {
                    $due_today = $due_today + $invoice->amount;
                } elseif ($daysTillMaturity > 0) {
                    if ($daysTillPD <= 30) {
                        $due_in_0_30_days = $due_in_0_30_days + $invoice->amount;
                    } elseif ($daysTillPD > 30 && $daysTillPD <= 60) {
                        $due_in_31_60_days = $due_in_31_60_days + $invoice->amount;
                    } elseif ($daysTillPD > 60 && $daysTillPD <= 90) {
                        $due_in_61_90_days = $due_in_61_90_days + $invoice->amount;
                    } else {
                        $due_in_90_days = $due_in_90_days + $invoice->amount;
                    }
                } elseif ($daysTillMaturity < 0) {
                    if ($daysTillPD >= 0) {
                        $within_grace_period = $within_grace_period + $invoice->amount;
                    } else {
                        if (abs($daysTillPD) <= 30) {
                            $overdue_1_30_days = $overdue_1_30_days + $invoice->amount;
                        } elseif (abs($daysTillPD) > 30 && abs($daysTillPD) <= 60) {
                            $overdue_31_60_days = $overdue_31_60_days + $invoice->amount;
                        } elseif (abs($daysTillPD) > 60 && abs($daysTillPD) <= 90) {
                            $overdue_61_90_days = $overdue_61_90_days + $invoice->amount;
                        } else {
                            $overdue_90_days = $overdue_90_days + $invoice->amount;
                        }
                    }
                }
            }
        }

        $withinDueDate = $due_in_0_30_days + $due_in_31_60_days + $due_in_61_90_days + $due_in_90_days + $due_today;
        $withinGracePeriod = $within_grace_period;
        $overdue = $overdue_1_30_days + $overdue_31_60_days + $overdue_61_90_days + $overdue_90_days;

        $totalAmountDue = $invoicesData->where('due_status', 'Pending')->latest()->sum('amount');

        $totalDues = $withinDueDate + $within_grace_period;

        $data = [
            'noInvoicesFinanced'  => $noInvoicesFinanced,
            'grandTotalFinanced'  => $grandTotalFinanced,
            'noOfSuppliers'       => $noOfSuppliers,
            'buyerLimit'          => $buyerLimit,
            'totalAmountDue'      => $totalAmountDue,
            'due_in_0_30_days'    => $due_in_0_30_days,
            'due_in_31_60_days'   => $due_in_31_60_days,
            'due_in_61_90_days'   => $due_in_61_90_days,
            'due_in_90_days'      => $due_in_90_days,
            'overdue_1_30_days'   => $overdue_1_30_days,
            'overdue_31_60_days'  => $overdue_31_60_days,
            'overdue_61_90_days'  => $overdue_61_90_days,
            'overdue_90_days'     => $overdue_90_days,
            'within_grace_period' => $within_grace_period,
            'due_today'           => $due_today,
            'withinDueDate'       => $withinDueDate,
            'withinGracePeriod'   => $withinGracePeriod,
            'overdue'             => $overdue,
            'totalDues'           => $totalDues,
            'suppliers'           => $suppliers,
            'batches'             => $batches,
            'supplierId'          => $supplierId,
            'batchId'             => $batchId
        ];
        // echo "<pre>"; print_r($data); die;

        return view('dashboards.buyer.index', $data);
        // return view('dashboards.buyer.index', compact(
        //     'instructors',
        //     'students',
        //     'total_due',
        //     'overdue',
        //     'due_within_30_days',
        //     'due_within_60_days',
        //     'due_within_90_days',
        //     'FundingReceived',
        //     'NumberOfInvoices',
        //     'AmountPendingConfirmation',
        //     'AmountPendingPayment'
        // ));
    }

    public function checkPaidInvoice()
    {
        $buyer = Auth::user();
        // $invoicePaidAlertIds = InvoicePaidAlert::where('buyer_id', $buyer->id)->pluck('invoice_id')->toArray();
        $invoices = Invoice::where('payment_status', 'Paid')->where('due_status', 'Pending')->where('buyer_id', Auth::user()->id)->latest()->get();
        $dueInvoices = Invoice::where('payment_status', 'Paid')->where('buyer_id', Auth::user()->id)->latest()->get();
        $dueCount = 0;
        foreach ($dueInvoices as $key => $dueInvoice) {
          $Link = Link::where('supplier_id', $dueInvoice->supplier_id)->where('buyer_id', $dueInvoice->buyer_id)->where('created_at', '<=', $dueInvoice->created_at)->latest()->first();
          $appliedTenor = 0;
          if (isset($Link)) {
              $payment_type = $Link->payment_type;
              if ($payment_type == "Monthly") {
                  $payment_term = $dueInvoice->payment_terms_days;
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
                  $payment_term = $dueInvoice->payment_terms_days;
                  if ($payment_term > 90) {
                      $appliedTenor = 90;
                  } else {
                      $appliedTenor = $payment_term;
                  }
              }
              $grace_period = $Link->grace_period;
          } else {
              $grace_period = 0;
          }

          if ($Link && $Link->fixed_tenor && $Link->payment_type == "Fixed") {
              $appliedTenor = $Link->fixed_tenor;
          }
          if (isset($dueInvoice->tenor_days)) {
              $appliedTenor = $dueInvoice->tenor_days;
          }

          if (isset($dueInvoice->estimated_disbursement_date)) {
              $disbursement_date = new \Carbon\Carbon($dueInvoice->estimated_disbursement_date);
              $RepaymentDueDate = date('Y-m-d', strtotime($disbursement_date->addDay($appliedTenor)));;
          } else {
              $RepaymentDueDate = date('Y-m-d');
          }
          $paymentDueDate = 'N/A';
          if (isset($RepaymentDueDate) && isset($dueInvoice->estimated_disbursement_date)) {
              $disbursement_date = new \Carbon\Carbon($dueInvoice->estimated_disbursement_date);
              $maturityDate      = $disbursement_date->addDay($appliedTenor);
              $buyer_id    = $dueInvoice->buyer_id;
              $buyer = User::find($buyer_id);
              if (isset($buyer->setting->grace_period)) {
                  $paymentDueDate = date('d-m-Y', strtotime($maturityDate->addDay($buyer->setting->grace_period)));
              }
          }

          $today = date('Y-m-d');

          $toRepaymentDueDate = new \Carbon\Carbon($RepaymentDueDate);
          $fromToday = new \Carbon\Carbon($today);

          $daysTillMaturity = $fromToday->diffInDays($toRepaymentDueDate);
          // $daysTillMaturity = $days;
          $end_of_grace_period = $toRepaymentDueDate->addDay($grace_period);

          if ($toRepaymentDueDate < $fromToday) {
              $daysTillMaturity = "-".$daysTillMaturity;
              // $days = -$days;
          }

          // $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);
          //
          // $daysTillPD = $fromToday->diffInDays($toPaymentDueDate);
          // // $daysTillPD = $days;
          //
          // if ($toPaymentDueDate < $fromToday):
          //     $daysTillPD = "-".$daysTillPD;
          //     // $days = -$days;
          // endif;
          //
          // $lateFees = 0;
          // if ($fromToday > $toPaymentDueDate):
          //     $lateFeePercentage = ($dueInvoice->buyer->setting->late_fee) / 30;
          //     $count1 = ($lateFeePercentage / $dueInvoice->amount);
          //     $count2 = $count1 * 100;
          //     $lateFees = ABS($daysTillPD) * $count2;
          //     // $lateFees = ABS($daysTillPD)*(($dueInvoice->buyer->setting->late_fee)/30)*$dueInvoice->amount;
          // endif;
          // $totalAmountOutstanding = $lateFees + $dueInvoice->amount;

          if ($dueInvoice->due_status != "Paid" && $daysTillMaturity < 0) {
              $dueCount = $dueCount + $dueInvoice->amount;
          }
        }
        $amountTotal = 0;
        foreach ($invoices as $key => $invoice) {
            $amountTotal = $amountTotal + $invoice->amount;
        }
        // $invoicesNumber = Invoice::whereIn('id', $invoicePaidAlertIds)->pluck('invoice_number')->toArray();
        if ($amountTotal) {
            // $dueCount = $dueCount - $amountTotal;
            return response()->json(['amountTotal' => $amountTotal, 'dueCount' => $dueCount]);
        } else {
            return response()->json('error');
        }
    }

    // public function clearRecord()
    // {
    //     $buyer = Auth::user();
    //     $invoicePaidAlertIds = InvoicePaidAlert::where('buyer_id', $buyer->id)->delete();
    //
    //     return response()->json('success');
    // }

    public function profile() // show profile page
    {
        return view('dashboards.buyer.settings.profile');
    }

    public function changeProfile(Request $request) // update profile page
    {
        $validatedData = $request->validate([
            'email'        => 'required',
            'company_name' => 'required',
            'company_type' => 'required',
            'reg_no'       => 'required',
            'reg_address'  => 'required',
            'f_name'       => 'required',
            'l_name'       => 'required',
            'phone'        => 'required',
            'country'      => 'required',
        ]);

        $checkEmail = User::where('email', $request->get('email'))->where('id', '!=', Auth::User()->id)->count();
        if($checkEmail>0):
            return back()->with('error', 'The email has already been taken.');
        endif;
        $checkPhone = User::where('phone', $request->get('phone'))->where('id', '!=', Auth::User()->id)->count();
        if($checkPhone>0):
            return back()->with('error', 'The phone has already been taken.');
        endif;

        if($request->hasFile('avatar')):
            $file = $request->file('avatar');
            // Get filename with extension
            $filenameWithExt = $request->file('avatar')->getClientOriginalName();
            // Get just filename
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            // Get just ext
            $extension = $request->file('avatar')->getClientOriginalExtension();
            //Filename to store
            $fileNameToStore = time().'.'.$extension;
            // Upload Image
            $path = public_path().'/avatar/';
            $file->move($path, $fileNameToStore);
        else:
            $fileNameToStore = Auth::User()->avatar;
        endif;

        $user = Auth::user();

        // check if password is not empty
        $current_password = $request->get('current-password');
        if (isset($current_password)) {
            if (!(Hash::check($request->get('current-password'), Auth::user()->password))) {
                // The passwords matches
                return redirect()->back()->with("error","Your current password does not matches with the password you provided. Please try again.");
            }
            if(strcmp($request->get('current-password'), $request->get('new-password')) == 0){
                //Current password and new password are same
                return redirect()->back()->with("error","New Password cannot be same as your current password. Please choose a different password.");
            }
            $validatedData = $request->validate([
                'current-password' => 'required',
                'new-password' => 'required|string|min:8|confirmed',
            ]);

            // change password
            $user->password = bcrypt($request->get('new-password'));
        }

        $user->email        = $request->get('email');
        $user->company_name = $request->get('company_name');
        $user->company_type = $request->get('company_type');
        $user->reg_no       = $request->get('reg_no');
        $user->reg_address  = $request->get('reg_address');
        $user->f_name       = $request->get('f_name');
        $user->l_name       = $request->get('l_name');
        $user->phone        = $request->get('phone');
        $user->phone        = $request->get('phone');
        $user->country      = $request->get('country');
        $user->avatar       = $fileNameToStore;

        $user->save();
        return redirect()->back()->with("success","Profile updated successfully!");
    }

    public function linkedSuppliers()
    {
        $linkedSuppliers = Link::where('buyer_id', Auth::id())->where('is_default', 1)->latest()->get();

        return view('dashboards.buyer.linked-suppliers.index', compact('linkedSuppliers'));
    }

    public function exportLinkedSuppliers(Request $request)
    {
        $suppliersIds = $request->supplier_ids;
        if (!isset($suppliersIds)) {
            return redirect()->back()->with("error", "Please select at least one supplier to export.");
        }

        $ids = implode(',', $suppliersIds);
        return Excel::download(new ExportLinkedSupplier($ids), 'linked-suppliers.xlsx');
    }

    public function batchApproval()
    {
        $approvedInvoices = Invoice::where('buyer_id', Auth::id())->where('admin_approval_batch', 'Approved')->latest()->get();
        $batchGroups = $approvedInvoices->groupBy('batch_id');
        $invoices = [];
        $count = 0;
        foreach ($batchGroups as $key => $batchGroup) {
            $amount = 0;
            $amountDueToZvilo = 0;
            $dueDate = [];
            $endOfGracePeriod = [];
            foreach ($batchGroup as $invoiceKey => $invoice) {
                $buyer_id    = $invoice->buyer_id;
                $supplier_id = $invoice->supplier_id;

                $Link = Link::where('buyer_id', $buyer_id)
                                      ->where('supplier_id', $supplier_id)
                                      ->where('created_at', '<=', $invoice->created_at)
                                      ->latest()
                                      ->first();
                $buyer = User::find($buyer_id);

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

                $today = date('Y-m-d');
                $from = new \Carbon\Carbon($today);
                $RepaymentDueDate = date('Y-m-d', strtotime($from->addDay($appliedTenor)));

                $to = new \Carbon\Carbon($RepaymentDueDate);
                //
                $end_of_grace_period = $to->addDay($invoice->buyer->setting->grace_period);

                $payment_type = $Link->payment_type ?? '';
                if (isset($Link->admin_fee)) {
                    $admin_fee = $Link->admin_fee;
                    $day30discountfee = $Link->ddf_30_days;
                    $day60discountfee = $Link->ddf_60_days;
                    $day90discountfee = $Link->ddf_90_days;
                } elseif (isset($buyer->setting->admin_fee)) {
                    $admin_fee = $buyer->setting->admin_fee;
                    $day30discountfee = $buyer->setting->ddf_30_days;
                    $day60discountfee = $buyer->setting->ddf_60_days;
                    $day90discountfee = $buyer->setting->ddf_90_days;
                } else {
                    $admin_fee = 0;
                    $day30discountfee = 0;
                    $day60discountfee = 0;
                    $day90discountfee = 0;
                }

                $admin_fee_earned = ($invoice->amount / 100) * $admin_fee;

                // Applied discount fee
                if ($payment_type == "Monthly") {
                    if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                        $appliedDiscountFee = ($day30discountfee / 30) * 30;
                    } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                        $appliedDiscountFee = ($day60discountfee / 60) * 60;
                    } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                        $appliedDiscountFee = ($day90discountfee / 90) * 90;
                    }

                    $appliedDiscountFee = number_format((float) $appliedDiscountFee, 2, '.', '');

                    $DiscountFeeEarned = ($invoice->amount / 100) * $appliedDiscountFee;
                } else {
                    $appliedDiscountFee = 0;

                    if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                        $appliedDiscountFee = ($day30discountfee / 30) * $appliedTenor;
                    } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                        $appliedDiscountFee = ($day60discountfee / 60) * $appliedTenor;
                    } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                        $appliedDiscountFee = ($day90discountfee / 90) * $appliedTenor;
                    }

                    $appliedDiscountFee = number_format((float) $appliedDiscountFee, 2, '.', '');

                    $DiscountFeeEarned = ($invoice->amount / 100) * $appliedDiscountFee;
                }

                $TotalFee = $DiscountFeeEarned + $admin_fee_earned;

                $euriborPer = 0;
                if (isset($Link) && $Link->euribor_per && $Link->euribor_type == '3_months') {
                    $euriborPer = $Link->euribor_per * ($appliedTenor / 365);
                }
                $euriborPerNumber = sprintf('%0.3f', $euriborPer);
                $euriborFeeEaned = ($euriborPerNumber/100) * $invoice->amount;
                $totalFeeExclLateFees = $euriborFeeEaned + $TotalFee;

                $paymentDueDate = 'N/A';
                if(isset($RepaymentDueDate) && isset($invoice->estimated_disbursement_date)):
                    $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                    $maturityDate      = $disbursement_date->addDay($appliedTenor);
                    if (isset($buyer->setting->grace_period)) {
                        $paymentDueDate = date('d-m-Y', strtotime($maturityDate->addDay($buyer->setting->grace_period)));
                    }
                endif;

                $lateFees = 0;
                $totalAmountOutstanding = 0;
                if ($paymentDueDate != 'N/A') {
                    $now = date('Y-m-d');
                    $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);
                    $fromToday = new \Carbon\Carbon($now);
                    $daysTillPD = $fromToday->diffInDays($toPaymentDueDate);

                    if ($fromToday > $toPaymentDueDate):
                        $lateFeePercentage = ($invoice->buyer->setting->late_fee) / 30;
                        $count1 = ($lateFeePercentage / $invoice->amount);
                        $count2 = $count1 * 100;
                        $lateFees = ABS($daysTillPD) * $count2;
                    endif;
                    $totalAmountOutstanding = $lateFees + $invoice->amount;
                }

                if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
                    $disbursementAmount = $invoice->amount;
                    $totalAmountOutstanding = $invoice->amount + $lateFees + $TotalFee + $euriborFeeEaned;
                } else {
                    $disbursementAmount = $invoice->amount - $totalFeeExclLateFees;
                    $totalAmountOutstanding = $invoice->amount;
                }

                $amount               = $amount + $disbursementAmount;
                $amountDueToZvilo     = $amountDueToZvilo + $totalAmountOutstanding;
                $uploadId             = $invoice->batch_ref;
                $status               = $invoice->buyer_approval_batch;
                $RepaymentDueDate     = new \Carbon\Carbon($RepaymentDueDate);
                $dueDate[$invoiceKey] = date('d-m-Y', strtotime($RepaymentDueDate->addDay($appliedTenor)));
                $end_of_grace_period  = new \Carbon\Carbon($end_of_grace_period);
                $endOfGracePeriod[$invoiceKey] = date('d-m-Y', strtotime($end_of_grace_period->addDay($appliedTenor)));
            }
            $dueDate               = array_unique($dueDate);
            $dueDateCheck          = (count($dueDate) > 1) ? 'Multiple' : $dueDate[0];
            $endOfGracePeriod      = array_unique($endOfGracePeriod);
            $endOfGracePeriodCheck = (count($endOfGracePeriod) > 1) ? 'Multiple' : $endOfGracePeriod[0];

            $invoices[$count]['upload_id'] = $uploadId;
            $invoices[$count]['batch_id']  = $key;
            $invoices[$count]['due_date']  = $dueDateCheck;
            $invoices[$count]['amount']    = $amount;
            $invoices[$count]['status']    = $status;
            $invoices[$count]['amount_due_to_zvilo'] = $amountDueToZvilo;
            $invoices[$count]['end_of_grace_period'] = $endOfGracePeriodCheck;
            $count++;
        }

        return view('dashboards.buyer.batch-approval.index', compact('invoices'));
    }

    public function exportBatchApproval(Request $request)
    {
        $batchIds = $request->invoiceIds;
        if (!isset($batchIds)) {
            return redirect()->back()->with("error", "No data available to export.");
        }

        $ids = implode(',', $batchIds);
        return Excel::download(new ExportBatchApproval($ids), 'batch-approval.xlsx');
    }

    public function batchApprovalAction(Request $request)
    {
        $batchIds = $request->batch_id;
        if (!isset($batchIds)) {
            return redirect()->back()->with("error", "Please select at least one batch to $request->batchApproval.");
        }

        // if ($request->batchApproval == 'approve') {
        //     if (count($batchIds) > 1) {
        //         return redirect()->back()->with("error", "You can only approve one batch at a time.");
        //     }
        //
        //     return back();
        // }

        if ($request->batchApproval == 'reject') {
            $invoices = Invoice::whereIn('batch_id', $batchIds)->update([
                'buyer_approval_batch' => 'Rejected'
            ]);

            return back()->with('success', "Batch rejected successfully.");
        }

        if ($request->batchApproval == 'export') {
            $ids = implode(',', $batchIds);
            return Excel::download(new ExportBatchApproval($ids), 'batch-approval.xlsx');
        }
    }

    public function filterBatchApproval(Request $request)
    {
        $approvedInvoices = Invoice::where('buyer_id', Auth::id())->where('admin_approval_batch', 'Approved');
        if ($request->batch_ref_filter) {
            $approvedInvoices = $approvedInvoices->where('batch_ref', $request->batch_ref_filter);
        }
        if ($request->batch_id_filter) {
            $approvedInvoices = $approvedInvoices->where('batch_id', $request->batch_id_filter);
        }
        if ($request->due_date_filter_from && $request->due_date_filter_to || $request->end_of_grace_period_from && $request->end_of_grace_period_to) {
            $DateInvoices = Invoice::where('buyer_id', Auth::id())->where('admin_approval_batch', 'Approved')->latest()->get();
            $batchGroups = $DateInvoices->groupBy('batch_id');
            $invoices = [];
            $count = 0;
            $dueDateArr = [];
            $gracePeriodArr = [];
            $j = 0;
            $k = 0;
            foreach ($batchGroups as $key => $batchGroup) {
                $amount = 0;
                $amountDueToZvilo = 0;
                $dueDate = [];
                $endOfGracePeriod = [];
                foreach ($batchGroup as $invoiceKey => $invoice) {
                    $buyer_id    = $invoice->buyer_id;
                    $supplier_id = $invoice->supplier_id;
                    $Link = Link::where('buyer_id', $buyer_id)
                                          ->where('supplier_id', $supplier_id)
                                          ->where('created_at', '<=', $invoice->created_at)
                                          ->latest()
                                          ->first();

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

                    $today = date('Y-m-d');
                    $from = new \Carbon\Carbon($today);
                    $RepaymentDueDate = date('Y-m-d', strtotime($from->addDay($appliedTenor)));
                    $to = new \Carbon\Carbon($RepaymentDueDate);
                    $end_of_grace_period = $to->addDay($invoice->buyer->setting->grace_period);
                    $RepaymentDueDate     = new \Carbon\Carbon($RepaymentDueDate);
                    $dueDate[$invoiceKey] = date('d-m-Y', strtotime($RepaymentDueDate->addDay($appliedTenor)));
                    $end_of_grace_period  = new \Carbon\Carbon($end_of_grace_period);
                    $endOfGracePeriod[$invoiceKey] = date('d-m-Y', strtotime($end_of_grace_period->addDay($appliedTenor)));
                }
                $dueDate               = array_unique($dueDate);
                $dueDate               = array_values($dueDate);
                $endOfGracePeriod      = array_unique($endOfGracePeriod);
                $endOfGracePeriod      = array_values($endOfGracePeriod);

                $count++;
                if ($request->due_date_filter_from && $request->due_date_filter_to) {
                    $fromDate = Carbon::parse($request->due_date_filter_from);
                    $toDate = Carbon::parse($request->due_date_filter_to);
                    $dates = [];
                    $dates[0] = Carbon::parse($fromDate);
                    for ($i = 1; $i < 1000; $i++) {
                        $dates[$i] = Carbon::parse($fromDate)->addDay($i);

                        if ($dates[$i] == $toDate) {
                            break;
                        }
                    }

                    $dateInvoiceId = '';
                    foreach ($dueDate as $value) {
                        $dateValue = Carbon::parse($value);
                        if (in_array($dateValue, $dates)) {
                            $dateInvoiceId = $invoice->batch_id;
                            break;
                        }
                    }
                    if ($dateInvoiceId) {
                        $dueDateArr[$j++] = $dateInvoiceId;
                    }
                }
                if ($request->end_of_grace_period_from && $request->end_of_grace_period_to) {
                    $fromGracePeriod = Carbon::parse($request->end_of_grace_period_from);
                    $toGracePeriod = Carbon::parse($request->end_of_grace_period_to);
                    $gracePeriodDtes = [];
                    $gracePeriodDtes[0] = Carbon::parse($fromGracePeriod);
                    for ($i = 1; $i < 1000; $i++) {
                        $gracePeriodDtes[$i] = Carbon::parse($fromGracePeriod)->addDay($i);

                        if ($gracePeriodDtes[$i] == $toGracePeriod) {
                            break;
                        }
                    }

                    $gracePeriodInvoiceId = '';
                    foreach ($endOfGracePeriod as $gracePeriodValue) {
                        $gracePeriodValue = Carbon::parse($gracePeriodValue);
                        if (in_array($gracePeriodValue, $gracePeriodDtes)) {
                            $gracePeriodInvoiceId = $invoice->batch_id;
                            break;
                        }
                    }
                    if ($gracePeriodInvoiceId) {
                        $gracePeriodArr[$k++] = $gracePeriodInvoiceId;
                    }
                }
            }


            if ($request->due_date_filter_from && $request->due_date_filter_to) {
                $approvedInvoices = $approvedInvoices->whereIn('batch_id', $dueDateArr);
            }
            if ($request->end_of_grace_period_from && $request->end_of_grace_period_to) {
                $approvedInvoices = $approvedInvoices->whereIn('batch_id', $gracePeriodArr);
            }
        }
        $approvedInvoices = $approvedInvoices->latest()->get();

        $batchGroups = $approvedInvoices->groupBy('batch_id');
        $invoices = [];
        $count = 1;
        $html = '';
        foreach ($batchGroups as $key => $batchGroup) {
            $amount = 0;
            $amountDueToZvilo = 0;
            $dueDate = [];
            $endOfGracePeriod = [];
            foreach ($batchGroup as $invoiceKey => $invoice) {
                $buyer_id    = $invoice->buyer_id;
                $supplier_id = $invoice->supplier_id;

                $Link = Link::where('buyer_id', $buyer_id)
                                      ->where('supplier_id', $supplier_id)
                                      ->where('created_at', '<=', $invoice->created_at)
                                      ->latest()
                                      ->first();
                $buyer = User::find($buyer_id);

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

                $today = date('Y-m-d');
                $from = new \Carbon\Carbon($today);
                $RepaymentDueDate = date('Y-m-d', strtotime($from->addDay($appliedTenor)));

                $to = new \Carbon\Carbon($RepaymentDueDate);
                //
                $end_of_grace_period = $to->addDay($invoice->buyer->setting->grace_period);

                $payment_type = $Link->payment_type ?? '';
                if (isset($Link->admin_fee)) {
                    $admin_fee = $Link->admin_fee;
                    $day30discountfee = $Link->ddf_30_days;
                    $day60discountfee = $Link->ddf_60_days;
                    $day90discountfee = $Link->ddf_90_days;
                } elseif (isset($buyer->setting->admin_fee)) {
                    $admin_fee = $buyer->setting->admin_fee;
                    $day30discountfee = $buyer->setting->ddf_30_days;
                    $day60discountfee = $buyer->setting->ddf_60_days;
                    $day90discountfee = $buyer->setting->ddf_90_days;
                } else {
                    $admin_fee = 0;
                    $day30discountfee = 0;
                    $day60discountfee = 0;
                    $day90discountfee = 0;
                }

                $admin_fee_earned = ($invoice->amount / 100) * $admin_fee;

                // Applied discount fee
                if ($payment_type == "Monthly") {
                    if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                        $appliedDiscountFee = ($day30discountfee / 30) * 30;
                    } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                        $appliedDiscountFee = ($day60discountfee / 60) * 60;
                    } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                        $appliedDiscountFee = ($day90discountfee / 90) * 90;
                    }

                    $appliedDiscountFee = number_format((float) $appliedDiscountFee, 2, '.', '');

                    $DiscountFeeEarned = ($invoice->amount / 100) * $appliedDiscountFee;
                } else {
                    $appliedDiscountFee = 0;

                    if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                        $appliedDiscountFee = ($day30discountfee / 30) * $appliedTenor;
                    } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                        $appliedDiscountFee = ($day60discountfee / 60) * $appliedTenor;
                    } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                        $appliedDiscountFee = ($day90discountfee / 90) * $appliedTenor;
                    }

                    $appliedDiscountFee = number_format((float) $appliedDiscountFee, 2, '.', '');

                    $DiscountFeeEarned = ($invoice->amount / 100) * $appliedDiscountFee;
                }

                $TotalFee = $DiscountFeeEarned + $admin_fee_earned;


                $paymentDueDate = 'N/A';
                if(isset($RepaymentDueDate) && isset($invoice->estimated_disbursement_date)):
                    $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                    $maturityDate      = $disbursement_date->addDay($appliedTenor);
                    if (isset($buyer->setting->grace_period)) {
                        $paymentDueDate = date('d-m-Y', strtotime($maturityDate->addDay($buyer->setting->grace_period)));
                    }
                endif;

                $lateFees = 0;
                $totalAmountOutstanding = 0;
                if ($paymentDueDate != 'N/A') {
                    $now = date('Y-m-d');
                    $toPaymentDueDate = new \Carbon\Carbon($paymentDueDate);
                    $fromToday = new \Carbon\Carbon($now);
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
                $totalFeeExclLateFees = $euriborFeeEaned + $TotalFee;

                if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
                    $disbursementAmount = $invoice->amount;
                    $totalAmountOutstanding = $invoice->amount + $lateFees + $TotalFee + $euriborFeeEaned;
                } else {
                    $disbursementAmount = $invoice->amount - $totalFeeExclLateFees;
                    $totalAmountOutstanding = $invoice->amount;
                }

                $amount               = $amount + $disbursementAmount;
                $amountDueToZvilo     = $amountDueToZvilo + $totalAmountOutstanding;
                $uploadId             = $invoice->batch_ref;
                $status               = $invoice->buyer_approval_batch;
                $RepaymentDueDate     = new \Carbon\Carbon($RepaymentDueDate);
                $dueDate[$invoiceKey] = date('d-m-Y', strtotime($RepaymentDueDate->addDay($appliedTenor)));
                $end_of_grace_period  = new \Carbon\Carbon($end_of_grace_period);
                $endOfGracePeriod[$invoiceKey] = date('d-m-Y', strtotime($end_of_grace_period->addDay($appliedTenor)));
            }
            $dueDate               = array_unique($dueDate);
            $dueDateCheck          = (count($dueDate) > 1) ? 'Multiple' : $dueDate[0];
            $endOfGracePeriod      = array_unique($endOfGracePeriod);
            $endOfGracePeriodCheck = (count($endOfGracePeriod) > 1) ? 'Multiple' : $endOfGracePeriod[0];

            $acknowledgementLetter = '';
            if ($status == 'Approved') {
                $acknowledgementLetter = '<a href="'. asset('storage/acknowledgement-form/'.$key.'.pdf') .'" target="_blank">
                                              <i class="fa fa-file-pdf-o" aria-hidden="true" style="font-size:25px; color:#A80017"></i>
                                          </a>';
            }

            if ($status == 'Pending') {
              $action = '<button type="button" class="btn btn-zvilo-rust btn-focus accept-btn" data-upload="'. $uploadId .'" data-batch="'. $key .'" data-toggle="modal" data-target="#letterModalLong" onclick="acceptBatch(this)">
                            Accept
                        </button>
                        <a href="javascript:void(0)" data-href="'. route('reject-batch-approval', ['id' => $key]) .'" class="btn btn-danger reject" onclick="rejectBatch(this)">Reject</a>';

            } elseif ($status == 'Rejected') {
                  $action = 'Rejected';
            } else {
                  $action = 'Accepted';
            }

            $html .= '<tr>
                          <td>'.$count.'</td>
                          <td style="padding-left: 18px;">
                            <input form="ExportBuyerApprovalInvoices" type="hidden" name="invoiceIds[]" value="'.$key .'">
                            <div class="form-check">
                                <input form="batchApprovalAction" class="form-check-input foo" type="checkbox" value="'.$key .'" name="batch_id[]" id="invoice_'.$key .'">
                                <label class="form-check-label" for="invoice_'.$key .'"></label>
                            </div>
                          </td>
                          <td>'.$uploadId.'</td>
                          <td>'.$key.'</td>
                          <td>&euro;'.number_format($amount, 2).'</td>
                          <td>&euro;'.number_format($amountDueToZvilo, 2).'</td>
                          <td>'.$dueDateCheck.'</td>
                          <td>'.$endOfGracePeriodCheck.'</td>
                          <td>'.$acknowledgementLetter.'</td>
                          <td>'.$status.'</td>
                          <td>'.$action.'</td>
                      </tr>';
            $count++;
        }

        if (!$html) {
            $html = '<tr>
                        <td class="text-center" colspan="11">No data available in table</td>
                    </tr>';
        }
        $data = [
            'html' => $html
        ];

        return $data;
    }

    public function rejectBatchApproval($batchId)
    {
        $invoice = Invoice::where('batch_id', $batchId)->update([
            'buyer_approval_batch' => 'Rejected'
        ]);

        return back()->with('success', 'Batch rejected successfully.');
    }

    public function acknowledgementForm(Request $request)
    {
        $authUser = Auth::user();
        $acknowledgementForm = AcknowledgementForm::create([
            'user_id'             => $authUser->id,
            'batch_id'            => $request->batch_id,
            'upload_id'           => $request->upload_id,
            'accepted_at'         => Carbon::now(),
            'signature'           => $request->signature,
            'signatory_name'      => $request->signatory_name,
            'signatory_job_title' => $request->signatory_job_title
        ]);

        $invoice = Invoice::where('batch_id', $request->batch_id)->update([
            'buyer_approval_batch'        => 'Approved',
            'estimated_disbursement_date' => date('Y-m-d')
        ]);

        Mail::to($authUser->email)->send(new AcknowledgementFormMail($acknowledgementForm));

        $data = $this->appendixBData($request);
        // $batchInvoices = Invoice::where('batch_id', $request->batch_id)->get();
        // $groupInvoices = $batchInvoices->groupBy('group_id');
        $groupInvoices = $data['appendixA'];
        $batchInvoices = $data['appendixB'];
        $data = [
            'name'      => $acknowledgementForm->signatory_name,
            'job_title' => $acknowledgementForm->signatory_job_title,
            'date'      => $acknowledgementForm->accepted_at,
            'signature' => $acknowledgementForm->signature,
            'batch_id'  => $request->batch_id,
            'upload_id' => $request->upload_id,
            'company'   => $authUser->company_name,
            'address'   => $authUser->reg_address,
            'batchInvoices' => $batchInvoices,
            'groupInvoices' => $groupInvoices
        ];

        $pdf = PDF::loadView('dashboards.buyer.pdf.acknowledgement-form', $data);

        $content = $pdf->download()->getOriginalContent();
        $path = 'public/acknowledgement-form/'. $request->batch_id . '.pdf';
        \Storage::put($path, $content);

        return back()->with('success', 'Acknowledgement submit successfully.');
    }

    public function appendixBData(Request $request)
    {
        $batchInvoices = Invoice::where('batch_id', $request->batch_id)->get();

        $count = 1;
        $appendixB = '';
        foreach ($batchInvoices as $batchInvoice) {
            $buyer_id    = $batchInvoice->buyer_id;
            $supplier_id = $batchInvoice->supplier_id;
            $Link  = \App\Models\Link::where('supplier_id', $supplier_id)->where('buyer_id', $buyer_id)->where('created_at', '<=', $batchInvoice->created_at)->latest()->first();
            $buyer = \App\Models\User::find($buyer_id);

            $appliedTenor = 0;
            $payment_type = '';
            if (isset($Link)) {
                $payment_type = $Link->payment_type;
                if ($payment_type == "Monthly") {
                    $payment_term = $batchInvoice->payment_terms_days;
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
                    $payment_term = $batchInvoice->payment_terms_days;
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
            if (isset($batchInvoice->tenor_days)) {
                $appliedTenor = $batchInvoice->tenor_days;
            }
            if (isset($batchInvoice->estimated_disbursement_date)) {
                $disbursement_date = new \Carbon\Carbon($batchInvoice->estimated_disbursement_date);
                $RepaymentDueDate = date('d-m-Y', strtotime($disbursement_date->addDay($appliedTenor)));
            } else {
                $RepaymentDueDate = Carbon::now();
                $RepaymentDueDate = date('d-m-Y', strtotime($RepaymentDueDate->addDay($appliedTenor)));
            }

            $to = new \Carbon\Carbon($RepaymentDueDate);
            $end_of_grace_period = $to->addDay($batchInvoice->buyer->setting->grace_period);

            if (isset($Link->admin_fee)) {
                $admin_fee        = $Link->admin_fee;
                $day30discountfee = $Link->ddf_30_days;
                $day60discountfee = $Link->ddf_60_days;
                $day90discountfee = $Link->ddf_90_days;
            } elseif (isset($buyer->setting->admin_fee)) {
                $admin_fee = $buyer->setting->admin_fee;
                $day30discountfee = $buyer->setting->ddf_30_days;
                $day60discountfee = $buyer->setting->ddf_60_days;
                $day90discountfee = $buyer->setting->ddf_90_days;
            } else {
                $admin_fee = 0;
                $day30discountfee = 0;
                $day60discountfee = 0;
                $day90discountfee = 0;
            }

            $admin_fee_earned = ($batchInvoice->amount/100) * $admin_fee;

            if ($payment_type == "Monthly") {
                if ($appliedTenor >= 1 && $appliedTenor <= 30) {
                    $appliedDiscountFee = ($day30discountfee/30)*30;
                } elseif ($appliedTenor >= 31 && $appliedTenor <= 60) {
                    $appliedDiscountFee = ($day60discountfee/60)*60;
                } elseif ($appliedTenor >= 61 && $appliedTenor <= 90) {
                    $appliedDiscountFee = ($day90discountfee/90)*90;
                }

                $appliedDiscountFee = number_format((float)$appliedDiscountFee, 2, '.', '');
                $DiscountFeeEarned = ($batchInvoice->amount/100)*$appliedDiscountFee;
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
                $DiscountFeeEarned = ($batchInvoice->amount/100)*$appliedDiscountFee;
            }

            $TotalFee = $DiscountFeeEarned + $admin_fee_earned;

            $euriborPer = 0;
            if (isset($Link) && $Link->euribor_per && $Link->euribor_type == '3_months') {
                $euriborPer = $Link->euribor_per * ($appliedTenor / 365);
            }
            $euriborPerNumber = sprintf('%0.3f', $euriborPer);
            $euriborFeeEaned = ($euriborPerNumber/100) * $batchInvoice->amount;
            $totalFeeIncEuriborFee = $TotalFee + $euriborFeeEaned;

            if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
                $disbursementAmount = $batchInvoice->amount;
            } else {
                $disbursementAmount = $batchInvoice->amount-$totalFeeIncEuriborFee;
            }

            $appendixB .= '<tr>
            							<td>'. $batchInvoice->supplier->company_name .'</td>
            							<td>'. date('d/m/Y', strtotime($batchInvoice->issue_date)) .'</td>
                          <td>'. date('d/m/Y', strtotime($batchInvoice->due_date)) .'</td>
            							<td>'. $batchInvoice->invoice_number .'</td>
                          <td> €'. number_format($batchInvoice->amount, 2) .'</td>
            							<td>'. number_format($DiscountFeeEarned, 2) .'</td>
                          <td>'. number_format($admin_fee_earned, 2) .'</td>
                          <td>'. number_format($euriborFeeEaned, 2) .'</td>
                          <td>'. number_format($totalFeeIncEuriborFee, 2) .'</td>
                          <td>'. date('d/m/Y', strtotime($RepaymentDueDate)) .'</td>
                          <td>'. date('d/m/Y', strtotime($end_of_grace_period)) .'</td>
                          <td>'. number_format($disbursementAmount, 2) .'</td>
                          <td>'. $batchInvoice->group_id .'</td>
                      </tr>';
        }

        $appendixA = '';
        $invoiceArr = [];
        $batchTotalInvoiceAmount      = 0;
        $batchTotalDisbursementAmount = 0;
        $batchTotalReceivableAmount   = 0;
        foreach ($batchInvoices->groupBy('group_id') as $key => $invoices) {
            $totalInvoices = 0;
            $totalInvoiceAmount = 0;
            $totalDisbursementAmount = 0;
            $totalReceivableAmount = 0;
            foreach ($invoices as $invoice) {
                $totalInvoices           = $totalInvoices + $invoice->no_of_invoices;
                $totalInvoiceAmount      = $totalInvoiceAmount + $invoice->amount;

                $buyer_id    = $invoice->buyer_id;
                $supplier_id = $invoice->supplier_id;
                $Link  = \App\Models\Link::where('supplier_id', $supplier_id)->where('buyer_id', $buyer_id)->where('created_at', '<=', $invoice->created_at)->latest()->first();

                $buyer = \App\Models\User::find($buyer_id);

                $appliedTenor = 0;
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
                if (isset($invoice->tenor_days)) {
                    $appliedTenor = $invoice->tenor_days;
                }
                if (isset($invoice->estimated_disbursement_date)) {
                    $disbursement_date = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                    $RepaymentDueDate  = new \Carbon\Carbon($invoice->estimated_disbursement_date);
                } else {
                    $disbursement_date = Carbon::now();
                    $RepaymentDueDate  = Carbon::now();
                }
                $RepaymentDueDate  = date('d-m-Y', strtotime($RepaymentDueDate->addDay($appliedTenor)));

                $to = new \Carbon\Carbon($RepaymentDueDate);
                $end_of_grace_period = $to->addDay($invoice->buyer->setting->grace_period);

                if (isset($Link->admin_fee)) {
                    $admin_fee        = $Link->admin_fee;
                    $day30discountfee = $Link->ddf_30_days;
                    $day60discountfee = $Link->ddf_60_days;
                    $day90discountfee = $Link->ddf_90_days;
                } elseif (isset($buyer->setting->admin_fee)) {
                    $admin_fee = $buyer->setting->admin_fee;
                    $day30discountfee = $buyer->setting->ddf_30_days;
                    $day60discountfee = $buyer->setting->ddf_60_days;
                    $day90discountfee = $buyer->setting->ddf_90_days;
                } else {
                    $admin_fee = 0;
                    $day30discountfee = 0;
                    $day60discountfee = 0;
                    $day90discountfee = 0;
                }

                $admin_fee_earned = ($invoice->amount/100) * $admin_fee;

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

                $TotalFee = $DiscountFeeEarned + $admin_fee_earned;

                $euriborPer = 0;
                if (isset($Link) && $Link->euribor_per && $Link->euribor_type == '3_months') {
                    $euriborPer = $Link->euribor_per * ($appliedTenor / 365);
                }

                $euriborPerNumber = sprintf('%0.3f', $euriborPer);
                $euriborFeeEaned = ($euriborPerNumber/100) * $invoice->amount;
                $totalFeeIncEuriborFee = $TotalFee + $euriborFeeEaned;

                if (isset($Link) && $Link->fees_incurred_by == 'buyer') {
                    $disbursementAmount = $invoice->amount;
                    $receivableAmount = $invoice->amount + $TotalFee + $euriborFeeEaned;
                } else {
                    $disbursementAmount = $invoice->amount - $totalFeeIncEuriborFee;
                    $receivableAmount = $invoice->amount;
                }

                $totalDisbursementAmount = $totalDisbursementAmount + $disbursementAmount;
                $totalReceivableAmount   = $totalReceivableAmount + $receivableAmount;
            }
            $appliedTenor = $appliedTenor;
            $gracePeriod = count($invoices) > 0 ? optional($invoices[0]->buyer->setting)->grace_period : '';
            $totalPeriod = $appliedTenor + $gracePeriod;
            $appendixA .= '<tr>
                              <td>'. $key .'</td>
                              <td>'. $invoices[0]->supplier->company_name .'</td>
                              <td>'. $totalInvoices .'</td>
                              <td>€'. number_format($totalInvoiceAmount, 2) .'</td>
                              <td>€'. number_format($totalDisbursementAmount, 2) .'</td>
                              <td>€'. number_format($totalReceivableAmount, 2) .'</td>
                              <td>'. $appliedTenor. ' days' .'</td>
                              <td>'. $gracePeriod . ' days' .'</td>
                              <td>'. $totalPeriod . ' days' .'</td>
                              <td>'. date('d/m/Y', strtotime($disbursement_date)) .'</td>
                              <td>'. date('d/m/Y', strtotime($RepaymentDueDate)) .'</td>
                              <td>'. date('d/m/Y', strtotime($end_of_grace_period)) .'</td>
                          </tr>';

            $batchTotalInvoiceAmount      = $batchTotalInvoiceAmount + $totalInvoiceAmount;
            $batchTotalDisbursementAmount = $batchTotalDisbursementAmount + $totalDisbursementAmount;
            $batchTotalReceivableAmount   = $batchTotalReceivableAmount + $totalReceivableAmount;
        }

        $appendixA .= '<tr>
                          <th>Total</th>
                          <th></th>
                          <th></th>
                          <th>€'. number_format($batchTotalInvoiceAmount, 2) .'</th>
                          <th>€'. number_format($batchTotalDisbursementAmount, 2) .'</th>
                          <th>€'. number_format($batchTotalReceivableAmount, 2) .'</th>
                          <th></th>
                          <th></th>
                          <th></th>
                          <th></th>
                          <th></th>
                          <th></th>
                      </tr>';

        $data = [
            'appendixA' => $appendixA,
            'appendixB' => $appendixB
        ];

        return $data;
    }
}
