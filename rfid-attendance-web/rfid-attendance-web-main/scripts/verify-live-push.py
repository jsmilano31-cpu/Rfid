"""Send one controlled FCM probe from Hostinger to an isolated test client."""
from pathlib import Path
import importlib.util, json, subprocess, time, argparse

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('activation',ROOT/'scripts/activate-notification-push.py')
activation=importlib.util.module_from_spec(spec); spec.loader.exec_module(activation)

def send(envelope):
    # This helper accepts only our opt-in test client's local envelope, never a user id.
    payload={'message':{'token':envelope['token'],'data':{'account_id':'push-verification-only','notification_id':envelope['notification_id'],'revision':'1'},'android':{'priority':'high','ttl':'120s'},'webpush':{'headers':{'Urgency':'high','TTL':'120'}}}}
    import base64
    encoded=base64.b64encode(json.dumps(payload).encode()).decode()
    code=r'''
$class=new ReflectionClass(App\Services\FcmService::class);
$account=$class->getMethod('credentials')->invoke(null);
$token=$class->getMethod('accessToken')->invoke(null,$account);
[$status,$body]=$class->getMethod('request')->invoke(null,'https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send',base64_decode('PAYLOAD'),['Authorization: Bearer '.$token,'Content-Type: application/json']);
echo json_encode(['status'=>$status,'accepted'=>isset($body['name']),'error'=>$body['error']['status']??null]);
'''.replace('PAYLOAD',encoded)
    ftp=activation.ftp_tools.connect()
    try: result=activation.probe(ftp,code)
    finally: ftp.close()
    print(json.dumps(result),flush=True)
    if not result.get('accepted'): raise RuntimeError('FCM rejected test delivery')

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--android',action='store_true',required=True)
    parser.parse_args()
    deadline=time.monotonic()+120
    while time.monotonic()<deadline:
        result=subprocess.run([r'D:\android-sdk\platform-tools\adb.exe','shell','run-as','com.example.campustrack','cat','files/push-verification.json'],capture_output=True,text=True)
        if result.returncode==0:
            try: envelope=json.loads(result.stdout)
            except json.JSONDecodeError: pass
            else: send(envelope); return
        time.sleep(2)
    raise RuntimeError('Opt-in Android test did not publish a token')

if __name__=='__main__': main()
