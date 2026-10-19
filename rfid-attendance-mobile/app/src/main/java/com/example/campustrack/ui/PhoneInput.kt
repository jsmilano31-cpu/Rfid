package com.example.campustrack.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.PhilippineMobile

@Composable internal fun PhoneInput(value:String,change:(String)->Unit){val local=PhilippineMobile.local(value);OutlinedTextField(local,{raw->change(PhilippineMobile.local(raw))},label={Text("Phone number (optional)")},placeholder={Text("9XX XXX XXXX")},singleLine=true,modifier=Modifier.fillMaxWidth(),isError=!PhilippineMobile.valid(value),leadingIcon={Row(Modifier.padding(start=12.dp),horizontalArrangement=Arrangement.spacedBy(6.dp)){androidx.compose.foundation.Image(androidx.compose.ui.res.painterResource(com.example.campustrack.R.drawable.ic_philippine_flag),"Philippine flag",Modifier.size(24.dp,16.dp));Text("+63")}},supportingText={Text(if(PhilippineMobile.valid(value))"Accepts pasted +63, 63 or 09 formats. Leave blank if unavailable."else"Enter ten digits starting with 9.")},keyboardOptions=androidx.compose.foundation.text.KeyboardOptions(keyboardType=androidx.compose.ui.text.input.KeyboardType.Phone))}
