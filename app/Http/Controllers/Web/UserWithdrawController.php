<?php

namespace App\Http\Controllers\Web;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\SalonCategories;
use App\Models\SalonImages;
use App\Models\Salons;
use App\Models\ServiceImages;
use App\Models\Services;
use Illuminate\Support\Facades\Validator;
use App\Models\Users;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Session;
use App\Models\Bookings;
use App\Jobs\SendEmail;
use App\Models\UserNotification;
use App\Models\PlatformData;
use App\Models\PlatformEarningHistory;
use App\Models\SalonEarningHistory;
use App\Models\SalonPayoutHistory;
use App\Models\SalonReviews;
use App\Models\SalonWalletStatements;
use Carbon\Carbon;
use Mockery\Generator\StringManipulation\Pass\ConstantsPass;
use PHPUnit\TextUI\XmlConfiguration\Constant;
use Symfony\Component\VarDumper\Caster\ConstStub;
use App\Jobs\SendNotification;
use App\Models\SalonBookingSlots;
use App\Models\Taxes;
use App\Models\Fee;
use App\Models\UserWalletStatements;
use DB;
use Illuminate\Validation\ValidationException;
use App\Models\EventType;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\UserWalletRechargeLogs;
use App\Models\UserWithdrawRequest;
use App;
use App\Models\EmailTemplate;
use App\Models\AdminEmailTemplate;
use App\Models\Admin;
use Stripe;
use App\Models\Card;
use App\Models\CardsTransaction;
use App\Jobs\PlatformEarning;
use App\Models\SalonGallery;
use App\Models\SalonMapImage;
use App\Jobs\SendAdminNotification;
use App\Models\NotificationTemplate;
use App\Models\AdminNotificationTemplate;
use App\Models\Page;
use App\Models\Blog;
use App\Models\BankDetail;
use App\Models\Device;
use App\Models\Language;

class UserWithdrawController extends Controller
{
    function index()
    {
        $user_details = Users::where('id', Session::get('user_id'))->first();
        $banks = BankDetail::where('user_id', $user_details->id)->where('user_type', 'customer')->get();
        return view('web.banks.index', ['banks' => $banks, 'user_details' => $user_details]);
    }
    
    function create()
    {
        $user_details = Users::where('id', Session::get('user_id'))->first();
        
        return view('web.banks.create', ['user_details' => $user_details]);
    }
    
    function store(Request $request)
    {
        $user_details = Users::where('id', Session::get('user_id'))->first();
        
        $bank = BankDetail::create([
            'user_id' => $user_details->id,
            'user_type' => 'customer',
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_holder' => $request->account_holder,
            'swift_code' => $request->swift_code,
            'status' =>1
        ]);

        return redirect('user-banks')->with(['bank_message' => 'Account Added Successfully']);
    }
    
    function edit($id)
    {
        $bank = BankDetail::find($id);
        $user_details = Users::where('id', $bank->user_id)->first();

        return view('web.banks.edit', ['bank' => $bank, 'user_details' => $user_details]);
    }
    
    function update(Request $request, $id)
    {
        $bank = BankDetail::where('id', $id)->update([
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_holder' => $request->account_holder,
            'swift_code' => $request->swift_code,
            'status' =>1
        ]);

        return redirect('user-banks')->with(['bank_message' => 'Account Updated Successfully']);
    }
    
    function delete($id)
    {
        $bank = BankDetail::find($id);
        $bank->delete();

        return redirect('user-banks')->with(['bank_message' => 'Account Deleted Successfully']);
    }
    
    public function create_withdraw_request(Request $request)
    {
        $rules = [
            'amount'=>  'required',
            'bank_id' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->errors()->all();
            $msg = $messages[0];
            return response()->json(['status' => false, 'message' => $msg]);
        }
        
        $user_id = Session::get('user_id');
        $amount = $request->amount;
        $user = Users::find($user_id);
        $settings = GlobalSettings::first();
        
        if ($user == null) {
            return response()->json(['status' => false, 'message' => "User doesn't exists!"]);
        }
        
        if ( $amount > $user->wallet) {
            return response()->json(['status' => false, 'message' => "Not enough balance to withdraw!"]);
        }
        
        $fee = Fee::where('type','withdraw')->first();
        if($fee->maximum < $amount ){
            $message= "Amount should be minimum ". $settings->currency.number_format($fee->minimum,2)." and maximum ". $settings->currency.number_format($fee->maximum,2);
            return response()->json(['status' => false, 'message' =>$message]);    
        }
        
        if($fee->minimum > $amount){
            $message= "Amount should be minimum ". $settings->currency.number_format($fee->minimum,2)." and maximum ". $settings->currency.number_format($fee->maximum,2);
            return response()->json(['status' => false, 'message' =>$message]);    
        }
        
        $day_wise=$fee['day_wise'];
        $week_wise=$fee['week_wise'];
        $month_wise=$fee['month_wise'];
        $today=  date('Y-m-d');
        $month= date('m');
        $wallet_chehistory_days= UserWithdrawRequest::where('status','!=',2)->whereDate('created_at',$today)->sum('amount');
        $wallet_chehistory_week = UserWithdrawRequest::where('status','!=',2)->whereBetween('created_at', [\Carbon\Carbon::now()->startOfWeek(), \Carbon\Carbon::now()->endOfWeek()])->sum('amount');
        $wallet_chehistory_month= UserWithdrawRequest::where('status','!=',2)->whereMonth('created_at', $month)->sum('amount');

        if($wallet_chehistory_days>$day_wise){
            return response()->json(['status' => false, 'message' => "You have reached maximum daily limit"]);
        }
        
        if($wallet_chehistory_week>$week_wise){
            return response()->json(['status' => false, 'message' => "You have reached maximum weekly limit"]);
        }
        
        if($wallet_chehistory_month>$month_wise){
            return response()->json(['status' => false, 'message' => "You have reached maximum monthly limit"]);
        }
          
        $charge_amount= ($amount*$fee->charge_percent??0)/100;
        $total_amount=$amount-$charge_amount;
        
        $bank_details = BankDetail::where('id', $request->bank_id)->first();
         
        $withdraw = new UserWithdrawRequest();
        $withdraw->user_id = $user->id;
        $withdraw->request_number = GlobalFunction::generateUserWithdrawRequestNumber();
        $withdraw->bank_title = GlobalFunction::cleanString($bank_details->bank_name);
        $withdraw->amount = $amount;
        $withdraw->charge_percent=$fee->charge_percent;
        $withdraw->account_number = GlobalFunction::cleanString($bank_details->account_number);
        $withdraw->holder = GlobalFunction::cleanString($bank_details->account_holder);
        $withdraw->swift_code = GlobalFunction::cleanString($bank_details->swift_code);
        $withdraw->save();

        $summary = 'Withdraw request :' . $withdraw->request_number;
        GlobalFunction::addUserStatementEntry(
            $user->id,
            null,
            $amount,
            Constants::debit,
            Constants::withdraw,
            $summary,
            $fee->charge_percent??0,
            $charge_amount??0,
            $total_amount??0
        );

        $user->wallet = $user->wallet - $amount;
        $user->save();
        
        try{
            $wallet_details=UserWalletStatements::where('user_id',$user->id)->latest()->first();  
              
            $user_name=ucfirst($user->first_name.' '.$user->last_name);
            $created_at=date('D, d F Y',strtotime($wallet_details->created_at));
            $amount= $settings->currency.number_format($amount,2);
            $charge_amount= $settings->currency.number_format($charge_amount,2);
            $total_amount= $settings->currency.number_format($total_amount,2);
            
            $check_device = Device::where('user_id', $user->id)->first();
            if(!empty($check_device->language)){
                $act_lang = $check_device->language;
            }else{
                $act_lang = '1';
            }
            
            $email_template = EmailTemplate::find(8);
            if($act_lang == '1'){
                $subject = $email_template->email_subjects;
                $content = $email_template->email_content;
            }elseif($act_lang == '2'){
                $subject = $email_template->email_subject_pap;
                $content = $email_template->email_content_pap;
            }elseif($act_lang == '3'){
                $subject = $email_template->email_subject_nl;
                $content = $email_template->email_content_nl;
            }
            
            $content = str_replace(["{user}","{uuid}","{created_at}","{amount}","{fee}","{total}"], [$user_name,$wallet_details->transaction_id,$created_at,$total_amount,$charge_amount,$amount,],$content);
                 
            $details=[         
                "subject"=>$subject ,
                "message"=>$content,
                "to"=>$user->email,
            ];
            send_email($details);
            
            $superAdmin=Admin::where('user_type',1)->first();
            $bookingsuccesemail=AdminEmailTemplate::find(8);
            $admin_subject=$bookingsuccesemail->email_subjects??'';
            $admin_subject=str_replace(["{user}"],[$user_name],$admin_subject);
            $admin_content=$bookingsuccesemail->email_content??''; 
            $admin_content=str_replace(["{user}","{uuid}","{created_at}","{amount}","{fee}","{total}"],
            [$user_name,$wallet_details->transaction_id,$created_at,$total_amount,$charge_amount,$amount,],$admin_content);
                 
            $details=[         
                "subject"=>$admin_subject ,
                "message"=>$admin_content,
                "to"=>$superAdmin->email,
            ];
            send_email($details);
            
            $details=[         
                "subject"=>$admin_subject ,
                "message"=>$admin_content,
                "to"=>$settings->admin_email,
            ];
            send_email($details);
            
            $type="withdraw";
            
            $notification_template = NotificationTemplate::find(6);
            if($act_lang == '1'){
                $title = $notification_template->notification_subjects;
                $message = strip_tags($notification_template->notification_content);
            }elseif($act_lang == '2'){
                $title = $notification_template->notification_subject_pap;
                $message = strip_tags($notification_template->notification_content_pap);
            }elseif($act_lang == '3'){
                $title = $notification_template->notification_subject_nl;
                $message = strip_tags($notification_template->notification_content_nl);
            }
            
            $message = str_replace(["{total}"],[$amount],$message);
            
            $item = new UserNotification();
            $item->user_id = $user->id;
            $item->title = $title;
            $item->description = $message;
            $item->notification_type = $type;
            $item->temp_id = '6';
            $item->total = $amount;
            $item->save();
            
            GlobalFunction::sendPushToUser($title, $message, $user);

            $adminNotification=AdminNotificationTemplate::find(5);
            $title=$adminNotification->notification_subjects??'';
            $message=strip_tags($adminNotification->notification_content??'');
            $message=str_replace(["{total}","{user}"],[$amount,$user_name],$message);
            dispatch(new SendAdminNotification($title,$message,$type,$user->id));
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
        return GlobalFunction::sendSimpleResponse(true, 'withdraw request submitted successfully!');
    }
}