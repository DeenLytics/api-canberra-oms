<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Mr Traders - Invoice</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: DejaVu Sans, sans-serif;
            /* UTF-8 + ৳ support */
        }

        body {
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.5;
            font-size: 13px;
            padding: 20px;
        }

        .invoice {
            max-width: 1000px;
            margin: 0 auto;
            /* background: #fff; */
            padding: 25px;
            /* border: 1px solid #ccc; */
            /* dompdf ignores box-shadow */
            border-radius: 8px;
        }

        header {
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
            padding-bottom: 10px;
            text-align: center;
        }

        header img {
            height: 50px;
            margin-bottom: 10px;
        }

        h2 {
            color: #8d1c1c;
            margin: 0;
            font-size: 20px;
            text-transform: uppercase;
        }

        h3 {
            font-size: 14px;
            margin: 8px 0;
        }

        h4 {
            font-size: 13px;
            margin: 3px 0;
        }

        .row {
            width: 100%;
            display: block;
            clear: both;
        }

        .col-sm-6 {
            display: inline-block;
            width: 49%;
            vertical-align: top;
        }

        .col-sm-12 {
            display: block;
            width: 100%;
        }

        .text-center {
            text-align: center;
        }

        .text-end {
            text-align: right;
        }

        .text-start {
            text-align: left;
        }

        .text-success {
            color: #8d1c1c;
        }

        .text-dark {
            color: #343a40;
        }

        .fw-bold {
            font-weight: bold;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        table th,
        table td {
            border: 1px solid #dee2e6;
            padding: 8px;
        }

        table th {
            background: #f8f9fa;
            font-weight: bold;
        }

        .no-border td {
            border: none;
            padding: 4px 6px;
        }

        /* Spacing */
        .mt-3 {
            margin-top: 15px;
        }

        .mt-5 {
            margin-top: 30px;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .mb-2 {
            margin-bottom: 8px;
        }

        .custom-hr {
            border: none;
            height: 1.5px;
            background-color: #dee2e6;
            margin: 10px auto;
            width: 80%;
        }

        .capitalize {
            text-transform: capitalize
        }
    </style>
</head>

<body>

    <div class="invoice">

        <!-- Header -->
        <header>
            <img src="{{ public_path('canberra/logo.png') }}" alt="Logo">
            <h2>Canberra Limited</h2>
            <p>Rooted in Quality, Made with Pride</p>
            <h3 class="fw-bold text-dark">INVOICE</h3>
        </header>

        <!-- Bill Info -->
        <div class="row">
            <div class="col-sm-6">
                <h4 class="fw-bold text-dark">Bill No: {{ $order->invoice->id }}</h4>
            </div>
            <div class="col-sm-6 text-end">
                <h4>Date: {{ $order->invoice->issue_date }}</h4>
                <h4>Status: <span class="capitalize">{{ $order->invoice->status }}</span></h4>
            </div>
        </div>

        <!-- Customer Info -->
        <table class="no-border mt-3">
            <tr>
                {{-- customers.name holds the OUTLET and customers.shop_name the
                     CONTACT PERSON; this row said "Customer Name" over the outlet
                     and the row below said "Outlet" over the person. --}}
                <td class="fw-bold text-success" width="25%">Outlet:</td>
                <td>{{ $order->customer->name }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-success" width="25%">Customer Mobile:</td>
                <td>{{ $order->customer->mobile_number}}</td>
            </tr>
            <tr>
                <td class="fw-bold text-success">Contact:</td>
                <td>{{ $order->customer->shop_name }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-success">Address:</td>
                <td>{{ $order->customer->address}}</td>
            </tr>
        </table>

        <!-- Items -->
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">Sl.No</th>
                    <th>Item</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @if($order->items->count())
                @foreach ($order->items as $key => $item)
                <tr>
                    <td class="text-center text-success">{{ $key + 1 }}</td>
                    <td class="text-start">{{ $item->product_name }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-center">{{ $item->price }}</td>
                    <td class="text-center">{{ $item->total }}</td>
                </tr>
                @endforeach
                <tr>
                    <td colspan="4" class="fw-bold text-end text-success">Total:</td>
                    <td colspan="1" class="fw-bold text-center">{{ $order->total }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="fw-bold text-end text-success">Taka In Word:</td>
                    <td colspan="3" class="fw-bold capitalize">{{ number_to_words(round($order->total)) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="row mt-5">
            <div class="col-sm-6 text-center">
                <hr class="custom-hr">
                <p class="text-success mb-0">Receiver's Signature</p>
            </div>
            <div class="col-sm-6 text-center">
                <hr class="custom-hr">
                <p class="text-success mb-0">For Mr Traders</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-5">
            <p class="mb-0"><span class="fw-bold">Website:</span> www.canberralimited.com | <span
                    class="fw-bold">Email:</span> sales@canberralimited.com</p>
            <p class="mb-0"><span class="fw-bold">Contact:</span> Mr. Rubel 01926 217736, Mr. Masum 01951 519756</p>
        </div>

    </div>
</body>

</html>
