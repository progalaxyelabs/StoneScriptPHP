import { Injectable } from '@angular/core';
import { Website } from '../lib/website';

@Injectable({
  providedIn: 'root'
})
export class WebsitesService {

    list: Website[] = []

  constructor() { }
}
