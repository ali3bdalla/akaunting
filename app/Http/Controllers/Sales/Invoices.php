<?php

namespace App\Http\Controllers\Sales;

use App\Abstracts\Http\Controller;
use App\Exports\Sales\Invoices as Export;
use App\Http\Requests\Common\Import as ImportRequest;
use App\Http\Requests\Document\Document as Request;
use App\Imports\Sales\Invoices as Import;
use App\Jobs\Document\CreateDocument;
use App\Jobs\Document\DeleteDocument;
use App\Jobs\Document\DuplicateDocument;
use App\Jobs\Document\UpdateDocument;
use App\Jobs\Export\ExportHtmlToPDFJob;
use App\Models\Document\Document;
use App\Notifications\Sale\Invoice as Notification;
use App\Traits\Documents;
use File;
class Invoices extends Controller
{
    use Documents;

    /**
     * @var string
     */
    public $type = Document::INVOICE_TYPE;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $invoices = Document::invoice()->with('contact', 'transactions')->collect(['document_number'=> 'desc']);

        return $this->response('sales.invoices.index', compact('invoices'));
    }

    /**
     * Show the form for viewing the specified resource.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function show(Document $invoice)
    {
        // Get Invoice Totals
        foreach ($invoice->totals_sorted as $invoice_total) {
            $invoice->{$invoice_total->code} = $invoice_total->amount;
        }

        $total = money($invoice->total, $invoice->currency_code, true)->format();

        $invoice->grand_total = money($total, $invoice->currency_code)->getAmount();

        if (!empty($invoice->paid)) {
            $invoice->grand_total = round($invoice->total - $invoice->paid, config('money.' . $invoice->currency_code . '.precision'));
        }

        return view('sales.invoices.show', compact('invoice'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return view('sales.invoices.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $response = $this->ajaxDispatch(new CreateDocument($request));

        if ($response['success']) {
            $response['redirect'] = route('invoices.show', $response['data']->id);

            $message = trans('messages.success.added', ['type' => trans_choice('general.invoices', 1)]);

            flash($message)->success();
        } else {
            $response['redirect'] = route('invoices.create');

            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Duplicate the specified resource.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function duplicate(Document $invoice)
    {
        $clone = $this->dispatch(new DuplicateDocument($invoice));

        $message = trans('messages.success.duplicated', ['type' => trans_choice('general.invoices', 1)]);

        flash($message)->success();

        return redirect()->route('invoices.edit', $clone->id);
    }

    /**
     * Import the specified resource.
     *
     * @param  ImportRequest  $request
     *
     * @return Response
     */
    public function import(ImportRequest $request)
    {
        $response = $this->importExcel(new Import, $request, trans_choice('general.invoices', 2));

        if ($response['success']) {
            $response['redirect'] = route('invoices.index');

            flash($response['message'])->success();
        } else {
            $response['redirect'] = route('import.create', ['sales', 'invoices']);

            flash($response['message'])->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function edit(Document $invoice)
    {
        return view('sales.invoices.edit', compact('invoice'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Document $invoice
     * @param  Request  $request
     *
     * @return Response
     */
    public function update(Document $invoice, Request $request)
    {
        $response = $this->ajaxDispatch(new UpdateDocument($invoice, $request));

        if ($response['success']) {
            $response['redirect'] = route('invoices.show', $response['data']->id);

            $message = trans('messages.success.updated', ['type' => trans_choice('general.invoices', 1)]);

            flash($message)->success();
        } else {
            $response['redirect'] = route('invoices.edit', $invoice->id);

            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function destroy(Document $invoice)
    {
        $response = $this->ajaxDispatch(new DeleteDocument($invoice));

        $response['redirect'] = route('invoices.index');

        if ($response['success']) {
            $message = trans('messages.success.deleted', ['type' => trans_choice('general.invoices', 1)]);

            flash($message)->success();
        } else {
            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Export the specified resource.
     *
     * @return Response
     */
    public function export()
    {
        return $this->exportExcel(new Export, trans_choice('general.invoices', 2));
    }

    /**
     * Mark the invoice as sent.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function markSent(Document $invoice)
    {
        event(new \App\Events\Document\DocumentSent($invoice));

        $message = trans('documents.messages.marked_sent', ['type' => trans_choice('general.invoices', 1)]);

        flash($message)->success();

        return redirect()->back();
    }

    /**
     * Mark the invoice as cancelled.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function markCancelled(Document $invoice)
    {
        event(new \App\Events\Document\DocumentCancelled($invoice));

        $message = trans('documents.messages.marked_cancelled', ['type' => trans_choice('general.invoices', 1)]);

        flash($message)->success();

        return redirect()->back();
    }

    /**
     * Download the PDF file of invoice.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function emailInvoice(Document $invoice)
    {
        if (empty($invoice->contact_email)) {
            return redirect()->back();
        }

        $invoice = $this->prepareInvoice($invoice);

        $view = view($invoice->template_path, compact('invoice'))->render();
        $html = mb_convert_encoding($view, 'HTML-ENTITIES');

        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html);

        $file_name = $this->getDocumentFileName($invoice);

        $file = storage_path('app/temp/' . $file_name);

        $invoice->pdf_path = $file;

        // Save the PDF file into temp folder
        $pdf->save($file);

        // Notify the customer
        $invoice->contact->notify(new Notification($invoice, 'invoice_new_customer'));

        // Delete temp file
        File::delete($file);

        unset($invoice->paid);
        unset($invoice->template_path);
        unset($invoice->pdf_path);
        unset($invoice->reconciled);

        event(new \App\Events\Document\DocumentSent($invoice));

        flash(trans('documents.messages.email_sent', ['type' => trans_choice('general.invoices', 1)]))->success();

        return redirect()->back();
    }

    /**
     * Print the invoice.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function printInvoice(Document $invoice)
    {
        $invoice = $this->prepareInvoice($invoice);

        $view = view($invoice->template_path, compact('invoice'));

        return mb_convert_encoding($view, 'HTML-ENTITIES', 'UTF-8');
    }

    /**
     * Download the PDF file of invoice.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function pdfInvoice(Document $invoice)
    {
        $invoice = $this->prepareInvoice($invoice);

        $currency_style = true;

        $view = view($invoice->template_path, compact('invoice', 'currency_style'))->render();
        $file_name = $this->getDocumentFileName($invoice);
        return $this->dispatch(new ExportHtmlToPDFJob($view,$file_name));
    }

    /**
     * Mark the invoice as paid.
     *
     * @param  Document $invoice
     *
     * @return Response
     */
    public function markPaid(Document $invoice)
    {
        try {
            event(new \App\Events\Document\PaymentReceived($invoice, ['type' => 'income']));

            $message = trans('documents.messages.marked_paid', ['type' => trans_choice('general.invoices', 1)]);

            flash($message)->success();
        } catch(\Exception $e) {
            $message = $e->getMessage();

            flash($message)->error()->important();
        }

        return redirect()->back();
    }

    protected function prepareInvoice(Document $invoice)
    {
        $paid = 0;

        foreach ($invoice->transactions as $item) {
            $amount = $item->amount;

            if ($invoice->currency_code != $item->currency_code) {
                $item->default_currency_code = $invoice->currency_code;

                $amount = $item->getAmountConvertedFromDefault();
            }

            $paid += $amount;
        }

        $invoice->paid = $paid;

        $invoice->template_path = 'sales.invoices.print_' . setting('invoice.template');

        event(new \App\Events\Document\DocumentPrinting($invoice));

        return $invoice;
    }
}
