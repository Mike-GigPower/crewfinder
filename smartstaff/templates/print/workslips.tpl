{if $action == 'pdf'}
{assign var=pdfPadding value='padding-left: 15px; '}
{/if}

{foreach item=row from=$invoiceLines name=printinvoice}

	{if $ein != $row->ein}

		{if $smarty.foreach.printinvoice.index > 0}<br /><br /><br /><br /><br /><div style="page-break-after:always"></div>{/if}

		{assign var=ein value=$row->ein}

		<table width="100%" cellspacing="1" cellpadding="1" border="0" class="invoicehead">

			<tr class="border">

				<td class="border" align="center" width="20%">

					<h1>WORKSLIP</h1>

				</td>

				<td class="border">

					Employee #{$row->ein}<br />
					<b>{$row->lastname}</b>, {$row->firstname}

				</td>

				<td class="border" valign="top">

					Report for Week Ending:<br />
					<b>{$smarty.get.id|date_format}</b>

				</td>

			</tr>

		</table>

		<table width="100%" cellspacing="1" cellpadding="0" border="0" class="styledtable">

			<tr class="border">
				<th width="75">Date:</th>
				<th width="300">Call:</th>
				<th width="100" style="text-align: center !important;">Rate:</th>
				<th width="60" style="text-align: center !important;">$/hr:</th>
				<th width="60" style="text-align: center !important;">Hours:</th>
				<th style="text-align: right !important;">Call Gross:</th>
			</tr>

		</table>

	{/if}

	<table width="100%" cellspacing="1" cellpadding="4" class="styledtable">

			{if $row->calculated}
				{foreach from=$row->calculated item=calculated}
					{if $calculated->timeTotal gt 0}
						<tr class="border">
							<td width="75">{$row->start_date|date_format:'%d/%m/%y'}</td>
							<td width="300">{$row->name} - {$row->call_name}</td>
							<td width="100"><div style="{$pdfPadding}text-align: center;">{$calculated->description}</div></td>
							<td width="60"><div style="{$pdfPadding}text-align: center;">${$calculated->rate|number_format:2}</div></td>
							<td width="60"><div style="{$pdfPadding}text-align: center;">{$calculated->timeTotal}</div></td>
							<td><div style="{$pdfPadding}text-align: right;">${$calculated->timeTotal*$calculated->rate|number_format:2}</div></td>
						</tr>
					{/if}
				{/foreach}

			{else}

			{if $row->day_hours > 0}

				<tr class="border">
					<td width="75">{$row->start_date|date_format:'%d/%m/%y'}</td>
					<td width="300">{$row->name} - {$row->call_name}</td>
					<td width="100"><div style="{$pdfPadding}text-align: center;">{$row->day_desc}</div></td>
					<td width="60"><div style="{$pdfPadding}text-align: center;">${$row->day_rate|number_format:2}</div></td>
					<td width="60"><div style="{$pdfPadding}text-align: center;">{$row->day_hours}</div></td>
					<td><div style="{$pdfPadding}text-align: right;">${$row->day_hours*$row->day_rate|number_format:2}</div></td>
				</tr>

			{/if}

			{if $row->night_hours > 0}

				<tr class="border">
					<td width="75">{$row->start_date|date_format:'%d/%m/%y'}</td>
					<td width="300">{$row->name} - {$row->call_name}</td>
					<td width="100"><div style="{$pdfPadding}text-align: center;">{$row->night_desc}</div></td>
					<td width="60"><div style="{$pdfPadding}text-align: center;">${$row->night_rate|number_format:2}</div></td>
					<td width="60"><div style="{$pdfPadding}text-align: center;">{$row->night_hours}</div></td>
					<td><div style="{$pdfPadding}text-align: right;">${$row->night_rate*$row->night_hours|number_format:2}</div></td>
				</tr>

			{/if}

		{/if}

		</tr>

	</table>


	{/foreach}

	{literal}
	<script type="text/javascript">

		$(document).ready(function(){

			//window.print();

		});

	</script>
	{/literal}
