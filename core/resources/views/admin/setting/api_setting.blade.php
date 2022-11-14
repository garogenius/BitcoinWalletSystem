@extends('admin.layouts.app')
@section('panel')
    <div class="row mb-none-30">
        <div class="col-lg-12 col-md-12 mb-30">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.api.update') }}" method="POST">
                        @csrf

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold">@lang('Block.io API Key')</label>
                                    <input class="form-control form-control-lg" type="text" name="api" value="{{$general->api}}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold"> @lang('Block.io PIN Number') </label>
                                    <input class="form-control form-control-lg" type="text" name="pin" value="{{$general->pin}}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold">@lang('Block.io Wallet Address')</label>
                                    <input class="form-control form-control-lg" type="text" name="wallet" value="{{$general->wallet}}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold"> @lang('Block.io API Version') </label>
                                    <input class="form-control form-control-lg" type="number" name="api_version" value="{{$general->api_version}}" disabled>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold"> @lang('Wallet Limit') </label>
                                    <input class="form-control form-control-lg" type="number" name="wallet_limit" value="{{$general->wallet_limit}}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold"> @lang('Fixed Charge for Send Balance') </label>
                                    <div class="input-group">
                                        <input class="form-control form-control-lg" type="text" name="fixed_charge" value="{{ getAmount($general->fixed_charge, 2) }}">
                                        <div class="input-group-append">
                                            <span class="input-group-text">{{ __($general->cur_text) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-control-label font-weight-bold"> @lang('Percent Charge for Send Balance') </label>
                                    <div class="input-group">
                                        <input class="form-control form-control-lg" type="text" name="percent_charge" value="{{ getAmount($general->percent_charge, 2) }}">
                                        <div class="input-group-append">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn--primary btn-block btn-lg">@lang('Update')</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
