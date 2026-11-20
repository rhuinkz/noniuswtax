#!/usr/bin/env python3
"""Actual HTTP / PHP / MariaDB duplicate-supplier test with independent logins."""
import concurrent.futures
import http.cookiejar
import json
import os
import threading
import urllib.error
import urllib.parse
import urllib.request

BASE=os.getenv('NONIUS_TEST_URL','http://127.0.0.1:8907')
PASSWORD=os.getenv('NONIUS_TEST_PASSWORD','workshop-only-strong-password')
checks=0

def passed(label,condition):
    global checks
    assert condition, 'FAIL: '+label
    checks+=1
    print('PASS:',label,flush=True)

class School:
    def __init__(self,username):
        self.username=username
        self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        form=urllib.parse.urlencode({'identifier':username,'password':PASSWORD}).encode()
        self.req('/login.php',form,'application/x-www-form-urlencoded')
        status,j=self.req('/api.php?scope=suppliers')
        assert status==200 and j.get('csrf'),f'Login failed for {username}: {status}: {j}'
        self.csrf=j['csrf']
    def req(self,path,data=None,typ=None):
        headers={'Accept':'application/json'}
        if typ: headers['Content-Type']=typ
        if typ=='application/json': headers['X-CSRF-Token']=self.csrf
        request=urllib.request.Request(BASE+path,data=data,headers=headers)
        try:
            with self.http.open(request,timeout=30) as resp:
                status,body=resp.status,resp.read()
        except urllib.error.HTTPError as exc:
            status,body=exc.code,exc.read()
        try: return status,json.loads(body)
        except ValueError: return status,{'excerpt':body[:300].decode('utf-8','replace')}
    def save(self,tin,*,name='Workshop Test Supplier',id=None,version=None):
        supplier={'name':name,'tin':tin,'address':'Fictional Test Street, Tagbilaran City',
                  'zip':'6300','business_type':'Corporate','vat_status':'VAT Registered',
                  'transaction_profiles':[{'transaction_type':'Supplier of Goods',
                    'business_atc':'test-vat','ewt_atc':'test-ewt'}]}
        if id: supplier.update(id=id,version=version)
        data=json.dumps({'action':'saveMaster','kind':'suppliers','record':supplier}).encode()
        return self.req('/api.php',data,'application/json')
    def list(self):
        status,j=self.req('/api.php?scope=suppliers')
        assert status==200,(status,j)
        return j['suppliers']

def main():
    a,b=School('test_school_a'),School('test_school_b')
    passed('school A and school B independently authenticated',a.csrf!=b.csrf)
    code,first=a.save('800-999-001')
    passed('normal supplier save',code==200 and first.get('ok') is True)
    code,duplicate=b.save('80099900100000')
    passed('preexisting normalized TIN returns HTTP 409',code==409 and duplicate.get('code')=='SUPPLIER_TIN_EXISTS')
    passed('duplicate response points to existing supplier',duplicate.get('supplier',{}).get('id')==first.get('id'))
    passed('TIN is normalized to 14 digits',duplicate.get('supplier',{}).get('tin')=='80099900100000')
    code,inactive=a.save('666111222')
    passed('inactive supplier reported inactive',code==409 and inactive.get('supplier',{}).get('active') is False)
    code,blocked=b.save('800999001',id=first['id'],version=1)
    passed('school user cannot edit shared supplier',code==400 and 'only the Division' in blocked.get('error',''))
    try:
        urllib.request.urlopen(BASE+'/api.php?scope=suppliers',timeout=10)
        raise AssertionError('Unauthenticated supplier list succeeded')
    except urllib.error.HTTPError as exc:
        passed('unauthenticated supplier list blocked',exc.code==401)
    for seq in range(1,9):
        tin=f'70012{seq:04d}'
        dashed=f'{tin[:3]}-{tin[3:6]}-{tin[6:9]}-00000'
        barrier=threading.Barrier(2)
        def send(user,value):
            barrier.wait(timeout=12)
            return user.save(value,name='Concurrent Supplier')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            x=pool.submit(send,a,dashed)
            y=pool.submit(send,b,tin)
            outcomes=[x.result(timeout=45),y.result(timeout=45)]
        winners=[j for code,j in outcomes if code==200 and j.get('ok') is True]
        losers=[j for code,j in outcomes if code==409 and j.get('code')=='SUPPLIER_TIN_EXISTS']
        passed(f'round {seq}: one save and one structured 409',len(winners)==1 and len(losers)==1)
        passed(f'round {seq}: 409 references winner',losers[0].get('supplier',{}).get('id')==winners[0].get('id'))
        passed(f'round {seq}: only one normalized TIN in shared master',len([s for s in a.list() if s.get('tin')==tin+'00000'])==1)
        passed(f'round {seq}: other school sees supplier on refresh',len([s for s in b.list() if s.get('id')==winners[0].get('id')])==1)
    print(f'PASS: REAL PHP+MARIADB INTEGRATION: {checks} assertions',flush=True)

if __name__=='__main__':
    main()
